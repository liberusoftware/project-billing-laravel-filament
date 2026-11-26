<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\CustomerPortal\Actions\CreatePortalItem;
use Liberu\Billing\CustomerPortal\Actions\CreatePortalRequest;
use Liberu\Billing\CustomerPortal\Actions\TransitionPortalRequest;
use Liberu\Billing\CustomerPortal\Events\PortalItemCreated;
use Liberu\Billing\CustomerPortal\Events\PortalRequestCreated;
use Liberu\Billing\CustomerPortal\Models\PortalItem;
use Liberu\Billing\CustomerPortal\Models\PortalRequest;
use Liberu\Billing\CustomerPortal\Policies\PortalItemPolicy;
use Liberu\Billing\CustomerPortal\Policies\PortalRequestPolicy;
use Liberu\Billing\CustomerPortal\Queries\GetCustomerBillingSummary;
use Liberu\Billing\CustomerPortal\Queries\ListCustomerPortalRecords;
use Liberu\Billing\CustomerPortal\Queries\ListPortalItems;
use Liberu\Billing\CustomerPortal\Support\CustomerReference;

it('creates portal items and requests, validates errors, and lists records', function (): void {
    Event::fake([PortalItemCreated::class, PortalRequestCreated::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);

    $customerId = DB::table('customers')->insertGetId([
        'team_id' => $team->id,
        'name' => 'Portal Customer',
        'email' => 'portal-cust@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(CreatePortalItem::class)->handle(0, ['type' => 'tickets', 'subject' => 'Help']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(CreatePortalItem::class)->handle($team->id, ['type' => 'invalid', 'subject' => 'Help']))
        ->toThrow(InvalidArgumentException::class);

    $item = app(CreatePortalItem::class)->handle($team->id, [
        'type' => 'tickets',
        'subject' => 'Need support',
        'customer_id' => $customerId,
        'payload' => ['priority' => 'high'],
    ]);

    expect($item->subject)->toBe('Need support')
        ->and($item->customer_id)->toBe($customerId);
    Event::assertDispatched(PortalItemCreated::class, fn (PortalItemCreated $event): bool => $event->item->is($item));

    $itemNoCustomer = app(CreatePortalItem::class)->handle($team->id, [
        'type' => 'profile',
        'subject' => 'Update profile',
    ]);
    expect($itemNoCustomer->customer_id)->toBeNull();

    $itemsPage = app(ListPortalItems::class)->handle($team->id, 'tickets', 10);
    expect($itemsPage->total())->toBe(1);
    expect(app(ListPortalItems::class)->handle($team->id, null, 10)->total())->toBe(2);

    $request = app(CreatePortalRequest::class)->handle($team->id, [
        'name' => 'Upgrade plan',
        'status' => 'active',
        'metadata' => ['plan' => 'pro'],
    ]);
    expect($request->name)->toBe('Upgrade plan');
    Event::assertDispatched(PortalRequestCreated::class, fn (PortalRequestCreated $event): bool => $event->request->is($request));

    expect(fn () => app(TransitionPortalRequest::class)->handle($request, 'unknown'))
        ->toThrow(InvalidArgumentException::class);

    $closed = app(TransitionPortalRequest::class)->handle($request, 'closed');
    expect($closed->status)->toBe('closed');
    expect(fn () => app(TransitionPortalRequest::class)->handle($closed, 'active'))
        ->toThrow(InvalidArgumentException::class);

    $requestsPage = app(ListCustomerPortalRecords::class)->handle($team->id, 10);
    expect($requestsPage->total())->toBe(1);
    expect(PortalRequest::query()->forTeam($team->id)->count())->toBe(1);
});

it('evaluates portal item and request policies', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    Sanctum::actingAs($user, ['billing.customer-portal.read', 'billing.customer-portal.write']);

    $item = PortalItem::query()->create([
        'team_id' => $team->id,
        'type' => 'tickets',
        'status' => 'open',
        'subject' => 'Policy item',
    ]);
    $request = PortalRequest::query()->create([
        'team_id' => $team->id,
        'name' => 'Policy request',
        'status' => 'active',
    ]);

    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherUser->switchTeam($otherUser->ownedTeams()->sole());
    Sanctum::actingAs($otherUser, ['billing.customer-portal.read', 'billing.customer-portal.write']);

    $itemPolicy = new PortalItemPolicy();
    expect($itemPolicy->viewAny($user))->toBeTrue()
        ->and($itemPolicy->create($user))->toBeTrue()
        ->and($itemPolicy->view($user, $item))->toBeTrue()
        ->and($itemPolicy->update($user, $item))->toBeTrue()
        ->and($itemPolicy->viewAny(null))->toBeFalse()
        ->and($itemPolicy->create(null))->toBeFalse()
        ->and($itemPolicy->view($otherUser, $item))->toBeFalse()
        ->and($itemPolicy->update($otherUser, $item))->toBeFalse();

    $requestPolicy = new PortalRequestPolicy();
    expect($requestPolicy->viewAny($user))->toBeTrue()
        ->and($requestPolicy->create($user))->toBeTrue()
        ->and($requestPolicy->view($user, $request))->toBeTrue()
        ->and($requestPolicy->update($user, $request))->toBeTrue()
        ->and($requestPolicy->viewAny(null))->toBeFalse()
        ->and($requestPolicy->create(null))->toBeFalse()
        ->and($requestPolicy->view($otherUser, $request))->toBeFalse()
        ->and($requestPolicy->update($otherUser, $request))->toBeFalse();
});

it('handles edge cases in GetCustomerBillingSummary and CustomerReference', function (): void {
    $summary = app(GetCustomerBillingSummary::class);

    expect(fn () => $summary->handle(0, 1))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $summary->handle(1, 999999))
        ->toThrow(InvalidArgumentException::class);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();

    $customerId = DB::table('customers')->insertGetId([
        'team_id' => $team->id,
        'name' => 'Summary Customer',
        'email' => 'summary-cust@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), 999999, $team->id))
        ->toThrow(InvalidArgumentException::class);

    $missingTables = ['billing_invoices'];

    Schema::shouldReceive('hasTable')->andReturnUsing(
        function (string $table) use (&$missingTables): bool {
            return ! in_array($table, $missingTables, true);
        }
    );

    try {
        // Missing billing_invoices table
        $empty = $summary->handle($team->id, $customerId);
        expect($empty['history'])->toBe([])
            ->and($empty['status']['total_invoiced'])->toBe(0);

        // Missing billing_payment_allocations and billing_payments tables
        $missingTables = ['billing_payment_allocations', 'billing_payments'];
        $noPayments = $summary->handle($team->id, $customerId);
        expect($noPayments['trends'])->toBe([]);

        // Missing customers table in CustomerReference
        $missingTables = ['customers'];
        expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), 1, $team->id))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        Schema::clearResolvedInstances();
    }
});
