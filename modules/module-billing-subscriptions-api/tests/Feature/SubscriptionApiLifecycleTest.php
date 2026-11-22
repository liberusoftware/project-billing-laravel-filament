<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Subscriptions\Actions\ActivateSubscription;

uses(RefreshDatabase::class);

it('runs a complete subscription lifecycle through the published API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $teamId = $user->current_team_id;
    $customer = Customer::factory()->create(['team_id' => $teamId]);

    $planA = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => $teamId,
        'name' => 'Starter',
        'pricing_model' => 'flat',
        'currency' => 'USD',
        'unit_amount_minor' => 1000,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $planB = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => $teamId,
        'name' => 'Growth',
        'pricing_model' => 'flat',
        'currency' => 'USD',
        'unit_amount_minor' => 2500,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($user, ['billing.subscriptions.read', 'billing.subscriptions.write']);

    $subscriptionId = $this->postJson(route('billing.subscriptions.store'), [
        'customer_id' => $customer->getKey(),
        'pricing_plan_id' => $planA,
        'trial_days' => 7,
        'period_days' => 30,
        'auto_renew' => true,
        'id_protection' => true,
        'metadata' => ['source' => 'api'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-subscriptions')
        ->assertJsonPath('data.attributes.status', 'trialing')
        ->assertJsonPath('data.attributes.id_protection', true)
        ->json('data.id');

    $this->getJson(route('billing.subscriptions.index', [
        'per_page' => 10,
        'customer_id' => $customer->getKey(),
        'status' => 'trialing',
    ]))->assertOk()
        ->assertJsonPath('data.0.id', $subscriptionId)
        ->assertJsonPath('meta.current_page', 1);

    $this->getJson(route('billing.subscriptions.show', $subscriptionId))
        ->assertOk()
        ->assertJsonPath('data.id', $subscriptionId);

    $this->postJson(route('billing.subscriptions.pause', $subscriptionId))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'paused');

    $this->postJson(route('billing.subscriptions.resume', $subscriptionId))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');

    $this->postJson(route('billing.subscriptions.renew', $subscriptionId), ['period_days' => 45])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');

    $this->patchJson(route('billing.subscriptions.plan', $subscriptionId), ['pricing_plan_id' => $planB])
        ->assertOk()
        ->assertJsonPath('data.attributes.pricing_plan_id', $planB);

    $this->patchJson(route('billing.subscriptions.entitlements', $subscriptionId), [
        'entitlement_state' => ['active' => true, 'seats' => 12],
    ])->assertOk()
        ->assertJsonPath('data.attributes.entitlement_state.seats', 12);

    $this->postJson(route('billing.subscriptions.cancel', $subscriptionId))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'cancelled')
        ->assertJsonPath('data.attributes.auto_renew', false);

    app(ActivateSubscription::class)->execute([
        'team_id' => $teamId,
        'auto_renew' => false,
        'current_period_ends_at' => now()->subDay(),
    ]);

    $this->postJson(route('billing.subscriptions.expire'), ['as_of' => now()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.expired', 1);
});

it('returns an empty list for an API actor with no team', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['billing.subscriptions.read']);

    $this->getJson(route('billing.subscriptions.index', ['page' => ['size' => 5]]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
