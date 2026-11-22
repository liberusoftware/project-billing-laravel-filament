<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Subscriptions\Actions\ActivateSubscription;
use Liberu\Billing\Subscriptions\Enums\SubscriptionStatus;
use Liberu\Billing\Subscriptions\Livewire\Components\SubscriptionEntitlements;
use Liberu\Billing\Subscriptions\Livewire\Components\SubscriptionList;
use Liberu\Billing\Subscriptions\Models\Subscription;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('manages subscriptions through the Livewire SubscriptionList component', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $teamId = $user->current_team_id;
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.subscriptions.read', 'billing.subscriptions.write']);

    $customer = Customer::factory()->create(['team_id' => $teamId]);
    $planA = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => $teamId,
        'name' => 'Standard',
        'pricing_model' => 'flat',
        'currency' => 'USD',
        'unit_amount_minor' => 1500,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $planB = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => $teamId,
        'name' => 'Scale',
        'pricing_model' => 'flat',
        'currency' => 'USD',
        'unit_amount_minor' => 3000,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(SubscriptionList::class)
        ->assertSee('No subscriptions found.')
        ->set('showActivate', true)
        ->set('pricingPlanId', $planA)
        ->set('trialDays', 0)
        ->set('periodDays', 30)
        ->call('activate')
        ->assertHasNoErrors()
        ->assertSet('showActivate', false)
        ->assertSee('Subscription activated.');

    $subscription = Subscription::query()->where('team_id', $teamId)->sole();

    $component->call('renew', $subscription->getKey())
        ->assertSee('Subscription renewed.')
        ->set('pricingPlanId', $planB)
        ->call('changePlan', $subscription->getKey())
        ->assertSee('Subscription plan changed.')
        ->call('pause', $subscription->getKey())
        ->assertSee('Subscription paused.')
        ->call('resume', $subscription->getKey())
        ->assertSee('Subscription resumed.')
        ->call('cancel', $subscription->getKey())
        ->assertSee('Subscription cancelled.');

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->pricing_plan_id)->toBe($planB);

    $due = app(ActivateSubscription::class)->execute([
        'team_id' => $teamId,
        'customer_id' => $customer->getKey(),
        'auto_renew' => false,
        'current_period_ends_at' => now()->subDay(),
    ]);

    $component->set('filterCustomerId', $customer->getKey())
        ->set('filterStatus', 'active')
        ->assertSee((string) $due->getKey())
        ->call('expireDue')
        ->assertSee('1 subscription expired.');

    expect($due->fresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('updates subscription entitlements and rejects non-object JSON in SubscriptionEntitlements', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $teamId = $user->current_team_id;
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.subscriptions.read', 'billing.subscriptions.write']);

    $subscription = app(ActivateSubscription::class)->execute(['team_id' => $teamId]);

    Livewire::test(SubscriptionEntitlements::class)
        ->assertSee('Subscription '.$subscription->getKey())
        ->set('selectedSubscriptionId', $subscription->getKey())
        ->set('entitlements', '{"active":true,"seats":8}')
        ->call('updateEntitlements')
        ->assertHasNoErrors()
        ->assertSet('selectedSubscriptionId', null)
        ->assertSet('entitlements', '{}')
        ->assertSee('Subscription entitlements updated.');

    expect($subscription->fresh()->entitlement_state)->toBe(['active' => true, 'seats' => 8]);

    expect(fn () => Livewire::test(SubscriptionEntitlements::class)
        ->set('selectedSubscriptionId', $subscription->getKey())
        ->set('entitlements', '"scalar-string"')
        ->call('updateEntitlements'))
        ->toThrow(InvalidArgumentException::class, 'Entitlements must be a JSON object.');
});
