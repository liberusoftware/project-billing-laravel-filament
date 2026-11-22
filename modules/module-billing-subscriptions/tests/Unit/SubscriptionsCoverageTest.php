<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Subscriptions\Actions\ActivateSubscription;
use Liberu\Billing\Subscriptions\Actions\CancelSubscription;
use Liberu\Billing\Subscriptions\Actions\ChangeSubscriptionPlan;
use Liberu\Billing\Subscriptions\Actions\ExpireSubscriptions;
use Liberu\Billing\Subscriptions\Actions\PauseSubscription;
use Liberu\Billing\Subscriptions\Actions\RenewSubscription;
use Liberu\Billing\Subscriptions\Actions\ResumeSubscription;
use Liberu\Billing\Subscriptions\Actions\UpdateEntitlementState;
use Liberu\Billing\Subscriptions\Enums\SubscriptionStatus;
use Liberu\Billing\Subscriptions\Events\SubscriptionActivated;
use Liberu\Billing\Subscriptions\Events\SubscriptionCancelled;
use Liberu\Billing\Subscriptions\Events\SubscriptionEntitlementsUpdated;
use Liberu\Billing\Subscriptions\Events\SubscriptionExpired;
use Liberu\Billing\Subscriptions\Events\SubscriptionPaused;
use Liberu\Billing\Subscriptions\Events\SubscriptionPlanChanged;
use Liberu\Billing\Subscriptions\Events\SubscriptionRenewed;
use Liberu\Billing\Subscriptions\Events\SubscriptionResumed;
use Liberu\Billing\Subscriptions\Models\Subscription;
use Liberu\Billing\Subscriptions\Policies\SubscriptionPolicy;
use Liberu\Billing\Subscriptions\Queries\ListSubscriptions;
use Liberu\Billing\Subscriptions\Support\CustomerReference;

uses(RefreshDatabase::class);

it('rejects non-positive period days on activation and renewal', function (): void {
    expect(fn () => app(ActivateSubscription::class)->execute([
        'team_id' => 10,
        'period_days' => 0,
    ]))->toThrow(InvalidArgumentException::class, 'Subscription period must be positive.');

    $subscription = app(ActivateSubscription::class)->execute(['team_id' => 10]);

    expect(fn () => app(RenewSubscription::class)->execute($subscription, 0))
        ->toThrow(InvalidArgumentException::class, 'Renewal period must be positive.');
});

it('rejects pricing plan activation when pricing plans table is missing', function (): void {
    Schema::shouldReceive('hasTable')->once()->with('billing_pricing_plans')->andReturn(false);

    expect(fn () => app(ActivateSubscription::class)->execute([
        'team_id' => 10,
        'pricing_plan_id' => 1,
    ]))->toThrow(InvalidArgumentException::class, 'Subscription pricing plan reference is invalid.');
});

it('rejects pricing plan change when pricing plans table is missing', function (): void {
    $subscription = app(ActivateSubscription::class)->execute(['team_id' => 10]);

    Schema::shouldReceive('hasTable')->once()->with('billing_pricing_plans')->andReturn(false);

    expect(fn () => app(ChangeSubscriptionPlan::class)->execute($subscription, 1))
        ->toThrow(InvalidArgumentException::class, 'Subscription pricing plan reference is invalid.');
});

it('rejects customer reference when customers table is missing', function (): void {
    Schema::shouldReceive('hasTable')->once()->with('customers')->andReturn(false);

    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), 1, 10))
        ->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');
});

it('validates customer references for null, missing, foreign, and valid customers', function (): void {
    expect(CustomerReference::assertBelongsToTeam(app('db'), null, 10))->toBeNull();

    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), 999999, 10))
        ->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');

    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->getKey()]);

    expect(CustomerReference::assertBelongsToTeam(app('db'), $customer->getKey(), $team->getKey()))
        ->toBe($customer->getKey());

    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), $customer->getKey(), $team->getKey() + 1))
        ->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');
});

it('handles idempotent cancellation and stale cancellation concurrency guards', function (): void {
    Event::fake([SubscriptionCancelled::class]);

    $subscription = app(ActivateSubscription::class)->execute(['team_id' => 10]);
    $stale = Subscription::query()->findOrFail($subscription->getKey());

    $cancelled = app(CancelSubscription::class)->execute($subscription);
    expect($cancelled->status)->toBe(SubscriptionStatus::Cancelled);
    Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);

    // Already cancelled in-memory -> returns immediately without dispatching again
    $again = app(CancelSubscription::class)->execute($cancelled);
    expect($again->getKey())->toBe($cancelled->getKey());
    Event::assertDispatchedTimes(SubscriptionCancelled::class, 1);

    // Stale active in-memory model -> hits locked status check inside transaction
    $fromStale = app(CancelSubscription::class)->execute($stale);
    expect($fromStale->status)->toBe(SubscriptionStatus::Cancelled);
});

it('validates plan changes for terminal subscriptions, invalid ids, and clearing plan', function (): void {
    Event::fake([SubscriptionPlanChanged::class]);

    $planId = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => 10,
        'name' => 'Pro plan',
        'pricing_model' => 'flat',
        'currency' => 'USD',
        'unit_amount_minor' => 2000,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $subscription = app(ActivateSubscription::class)->execute([
        'team_id' => 10,
        'pricing_plan_id' => $planId,
    ]);

    expect(fn () => app(ChangeSubscriptionPlan::class)->execute($subscription, 0))
        ->toThrow(InvalidArgumentException::class, 'Pricing plan is invalid.');

    $cleared = app(ChangeSubscriptionPlan::class)->execute($subscription, null);
    expect($cleared->pricing_plan_id)->toBeNull();
    Event::assertDispatched(SubscriptionPlanChanged::class);

    $cancelled = app(CancelSubscription::class)->execute($cleared);

    expect(fn () => app(ChangeSubscriptionPlan::class)->execute($cancelled, $planId))
        ->toThrow(LogicException::class, 'A terminal subscription cannot change plans.');
});

it('guards stale state for resume and entitlement updates and terminal entitlement updates', function (): void {
    Event::fake([
        SubscriptionActivated::class,
        SubscriptionPaused::class,
        SubscriptionResumed::class,
        SubscriptionRenewed::class,
        SubscriptionEntitlementsUpdated::class,
        SubscriptionExpired::class,
    ]);

    $subscription = app(ActivateSubscription::class)->execute(['team_id' => 10]);
    $paused = app(PauseSubscription::class)->execute($subscription);
    $stalePaused = Subscription::query()->findOrFail($paused->getKey());

    // Resume directly in DB so $stalePaused is still paused in memory
    Subscription::query()->whereKey($paused->getKey())->update(['status' => SubscriptionStatus::Active->value]);

    expect(fn () => app(ResumeSubscription::class)->execute($stalePaused))
        ->toThrow(LogicException::class, 'Only paused subscriptions can be resumed.');

    // Renew with null current_period_ends_at
    $active = $paused->refresh();
    $renewed = app(RenewSubscription::class)->execute($active);
    expect($renewed->current_period_ends_at)->not->toBeNull();
    Event::assertDispatched(SubscriptionRenewed::class);

    // Update entitlements on stale active model whose persisted state became expired
    $staleActive = Subscription::query()->findOrFail($renewed->getKey());
    Subscription::query()->whereKey($renewed->getKey())->update(['status' => SubscriptionStatus::Expired->value]);

    expect(fn () => app(UpdateEntitlementState::class)->execute($staleActive, ['seats' => 5]))
        ->toThrow(LogicException::class, 'A terminal subscription cannot change entitlements.');

    // Update entitlements on already-terminal in-memory model
    $expired = $renewed->refresh();
    expect(fn () => app(UpdateEntitlementState::class)->execute($expired, ['seats' => 5]))
        ->toThrow(LogicException::class, 'A terminal subscription cannot change entitlements.');

    // Expire due subscriptions with default teamId and asOf
    $due = app(ActivateSubscription::class)->execute([
        'team_id' => 10,
        'auto_renew' => false,
        'current_period_ends_at' => now()->subDay(),
    ]);

    expect(app(ExpireSubscriptions::class)->execute())->toBe(1)
        ->and($due->refresh()->status)->toBe(SubscriptionStatus::Expired);
    Event::assertDispatched(SubscriptionExpired::class);
});

it('evaluates subscription policy for team scoping, shared records, and token abilities', function (): void {
    $policy = new SubscriptionPolicy();

    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;

    $ownSub = new Subscription(['team_id' => $user->current_team_id]);
    $sharedSub = new Subscription(['team_id' => null]);
    $otherSub = new Subscription(['team_id' => $otherTeam->getKey()]);

    // Non-token actor without tokenCan method
    $plainActor = (object) ['current_team_id' => $user->current_team_id];
    expect($policy->viewAny($plainActor))->toBeTrue()
        ->and($policy->create($plainActor))->toBeTrue()
        ->and($policy->view($plainActor, $ownSub))->toBeTrue()
        ->and($policy->view($plainActor, $sharedSub))->toBeTrue()
        ->and($policy->view($plainActor, $otherSub))->toBeFalse()
        ->and($policy->update($plainActor, $ownSub))->toBeTrue()
        ->and($policy->update($plainActor, $sharedSub))->toBeTrue()
        ->and($policy->update($plainActor, $otherSub))->toBeFalse();

    // Sanctum read-only actor
    Sanctum::actingAs($user, ['billing.subscriptions.read']);
    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $ownSub))->toBeTrue()
        ->and($policy->create($user))->toBeFalse()
        ->and($policy->update($user, $ownSub))->toBeFalse();

    // Sanctum write-only actor
    Sanctum::actingAs($user, ['billing.subscriptions.write']);
    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->update($user, $ownSub))->toBeTrue()
        ->and($policy->update($user, $otherSub))->toBeFalse();
});

it('returns empty results when listing subscriptions without a team', function (): void {
    app(ActivateSubscription::class)->execute(['team_id' => 10]);

    $result = app(ListSubscriptions::class)->execute(null, 0);

    expect($result->total())->toBe(0)
        ->and($result->perPage())->toBe(1);
});
