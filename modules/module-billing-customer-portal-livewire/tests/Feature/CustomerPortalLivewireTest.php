<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\CustomerPortal\Livewire\Components\PortalBilling;
use Liberu\Billing\CustomerPortal\Livewire\Components\PortalDashboard;
use Liberu\Billing\CustomerPortal\Livewire\Components\PortalItems;
use Liberu\Billing\CustomerPortal\Livewire\Components\PortalRequests;
use Liberu\Billing\CustomerPortal\Models\PortalItem;
use Liberu\Billing\CustomerPortal\Models\PortalRequest;
use Livewire\Livewire;

it('renders and manages customer portal Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.customer-portal.read', 'billing.customer-portal.write']);

    $customerId = DB::table('customers')->insertGetId([
        'team_id' => $team->id,
        'name' => 'LW Portal Customer',
        'email' => 'lw-portal@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // PortalBilling without and with customerId
    Livewire::test(PortalBilling::class)
        ->assertOk()
        ->set('customerId', $customerId)
        ->set('startAt', now()->subMonth()->toDateString())
        ->set('endAt', now()->addDay()->toDateString())
        ->assertOk();

    // PortalItems create + transition
    $itemsComponent = Livewire::test(PortalItems::class)
        ->set('type', 'tickets')
        ->set('subject', 'Livewire Ticket')
        ->set('customerId', $customerId)
        ->call('createItem')
        ->assertHasNoErrors();

    $item = PortalItem::query()->where('subject', 'Livewire Ticket')->sole();

    $itemsComponent
        ->set('selectedItemId', $item->id)
        ->set('status', 'completed')
        ->call('transitionItem')
        ->assertHasNoErrors();

    expect($item->fresh()->status)->toBe('completed');

    // PortalDashboard
    Livewire::test(PortalDashboard::class)
        ->assertOk()
        ->set('type', 'tickets')
        ->assertOk();

    // PortalRequests create + transition
    $requestsComponent = Livewire::test(PortalRequests::class)
        ->set('name', 'Livewire Request')
        ->set('status', 'active')
        ->call('createRequest')
        ->assertHasNoErrors();

    $request = PortalRequest::query()->where('name', 'Livewire Request')->sole();

    $requestsComponent
        ->set('selectedRequestId', $request->id)
        ->set('status', 'closed')
        ->call('transitionRequest')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('closed');
});

it('forbids customer portal Livewire components when no current team is active', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.customer-portal.read', 'billing.customer-portal.write']);

    Livewire::test(PortalItems::class)->assertForbidden();
    Livewire::test(PortalRequests::class)->assertForbidden();
    Livewire::test(PortalDashboard::class)->assertForbidden();
    Livewire::test(PortalBilling::class, ['customerId' => 1])->assertForbidden();
});
