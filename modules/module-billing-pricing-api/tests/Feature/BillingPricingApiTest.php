<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Pricing\Models\PricingDiscount;
use Liberu\Billing\Pricing\Models\PricingPlan;

uses(RefreshDatabase::class);

it('manages pricing plans, contracts, discounts, snapshots, proration, and usage via API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.pricing.read', 'billing.pricing.write']);

    // Create a usage plan
    $createPlan = $this->postJson('/api/v1/billing/pricing', [
        'name' => 'API Usage Plan',
        'pricing_model' => 'usage',
        'currency' => 'USD',
        'unit_amount_minor' => 25,
        'usage_unit' => 'call',
        'metadata' => ['tier' => 'pro'],
    ], ['Idempotency-Key' => 'create-plan-1']);

    $createPlan->assertCreated()
        ->assertJsonPath('data.type', 'billing-pricing-plan')
        ->assertJsonPath('data.attributes.name', 'API Usage Plan');

    $planId = (int) $createPlan->json('data.id');
    $plan = PricingPlan::query()->findOrFail($planId);

    // List plans
    $this->getJson('/api/v1/billing/pricing?per_page=10')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 1);

    // Create contract
    $createContract = $this->postJson('/api/v1/billing/pricing/contracts', [
        'pricing_plan_id' => $planId,
        'starts_at' => '2026-08-01 00:00:00',
        'ends_at' => '2026-09-01 00:00:00',
        'terms' => ['net' => 30],
        'status' => 'active',
    ], ['Idempotency-Key' => 'create-contract-1']);

    $createContract->assertCreated()
        ->assertJsonPath('data.type', 'pricing-contract')
        ->assertJsonPath('data.attributes.pricing_plan_id', $planId);

    // List contracts
    $this->getJson('/api/v1/billing/pricing/contracts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);

    // Capture snapshot
    $this->postJson("/api/v1/billing/pricing/plans/{$planId}/snapshot", [], ['Idempotency-Key' => 'snapshot-1'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'pricing-snapshot')
        ->assertJsonPath('data.attributes.version', 1);

    // List snapshots
    $this->getJson('/api/v1/billing/pricing/snapshots')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Create and redeem discount
    $discount = PricingDiscount::query()->create([
        'team_id' => $user->current_team_id,
        'code' => 'API10',
        'kind' => 'percentage',
        'value' => 10,
        'active' => true,
    ]);

    $this->getJson('/api/v1/billing/pricing/discounts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.code', 'API10');

    $this->postJson("/api/v1/billing/pricing/discounts/{$discount->getKey()}/redeem", [], ['Idempotency-Key' => 'redeem-1'])
        ->assertOk()
        ->assertJsonPath('data.type', 'pricing-discount')
        ->assertJsonPath('data.attributes.redemptions', 1);

    // Calculate proration
    $this->postJson('/api/v1/billing/pricing/proration', [
        'amount_minor' => 1000,
        'remaining_days' => 15,
        'period_days' => 30,
    ])->assertOk()
        ->assertJsonPath('data.amount_minor', 500);

    // Calculate usage
    DB::table('billing_usage_meters')->insert([
        'id' => 601,
        'team_id' => $plan->team_id,
        'name' => 'API Calls',
        'code' => 'api-calls-601',
        'unit' => 'call',
        'unit_price_minor' => 25,
        'currency' => 'USD',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('billing_usage_records')->insert([
        'team_id' => $plan->team_id,
        'customer_id' => null,
        'meter_id' => 601,
        'event_key' => 'api-usage-1',
        'quantity' => 4,
        'unit_price_minor' => 25,
        'amount_minor' => 100,
        'currency' => 'USD',
        'occurred_at' => '2026-08-15 12:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson("/api/v1/billing/pricing/plans/{$planId}/usage", [
        'meter_id' => 601,
        'start' => '2026-08-01 00:00:00',
        'end' => '2026-08-31 23:59:59',
    ])->assertOk()
        ->assertJsonPath('data.quantity', 4)
        ->assertJsonPath('data.amount_minor', 100);
});
