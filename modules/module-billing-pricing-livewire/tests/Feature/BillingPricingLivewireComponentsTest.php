<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Pricing\Livewire\Components\PricingPlanList;
use Liberu\Billing\Pricing\Livewire\Components\PricingSupport;
use Liberu\Billing\Pricing\Models\PricingDiscount;
use Liberu\Billing\Pricing\Models\PricingPlan;
use Liberu\Billing\Pricing\Models\PricingSnapshot;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('validates and creates pricing plans through the PricingPlanList Livewire component', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $this->actingAs($user);
    Sanctum::actingAs($user, ['billing.pricing.read', 'billing.pricing.write']);

    // Validation errors for recurring, usage, and tiered plans
    Livewire::test(PricingPlanList::class)
        ->set('name', 'Missing Interval')
        ->set('pricingModel', 'recurring')
        ->set('billingInterval', '   ')
        ->call('save')
        ->assertHasErrors(['billingInterval']);

    Livewire::test(PricingPlanList::class)
        ->set('name', 'Missing Usage Unit')
        ->set('pricingModel', 'usage')
        ->set('usageUnit', '')
        ->call('save')
        ->assertHasErrors(['usageUnit']);

    Livewire::test(PricingPlanList::class)
        ->set('name', 'Invalid Tiers')
        ->set('pricingModel', 'tiered')
        ->set('tiersJson', 'not-json')
        ->call('save')
        ->assertHasErrors(['tiersJson']);

    // Create a valid tiered plan
    Livewire::test(PricingPlanList::class)
        ->set('showCreate', true)
        ->set('name', 'Tiered Plan')
        ->set('pricingModel', 'tiered')
        ->set('currency', 'USD')
        ->set('unitAmountMinor', 0)
        ->set('tiersJson', json_encode([['up_to' => 10, 'unit_amount_minor' => 50]], JSON_THROW_ON_ERROR))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false)
        ->assertSee('Tiered Plan');

    expect(PricingPlan::query()->where('name', 'Tiered Plan')->where('team_id', $user->current_team_id)->exists())->toBeTrue();
});

it('calculates proration, captures snapshots, prices usage, and redeems discounts through PricingSupport', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $this->actingAs($user);
    Sanctum::actingAs($user, ['billing.pricing.read', 'billing.pricing.write']);

    $plan = PricingPlan::query()->create([
        'team_id' => $user->current_team_id,
        'name' => 'Livewire Usage Plan',
        'pricing_model' => 'usage',
        'currency' => 'USD',
        'unit_amount_minor' => 20,
        'usage_unit' => 'unit',
        'status' => 'draft',
    ]);
    $discount = PricingDiscount::query()->create([
        'team_id' => $user->current_team_id,
        'code' => 'LIVEWIRE15',
        'kind' => 'percentage',
        'value' => 15,
        'active' => true,
    ]);

    DB::table('billing_usage_meters')->insert([
        'id' => 801,
        'team_id' => $user->current_team_id,
        'name' => 'Livewire Meter',
        'code' => 'livewire-meter-801',
        'unit' => 'unit',
        'unit_price_minor' => 20,
        'currency' => 'USD',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('billing_usage_records')->insert([
        'team_id' => $user->current_team_id,
        'customer_id' => null,
        'meter_id' => 801,
        'event_key' => 'lw-usage-1',
        'quantity' => 5,
        'unit_price_minor' => 20,
        'amount_minor' => 100,
        'currency' => 'USD',
        'occurred_at' => '2026-08-12 12:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(PricingSupport::class)
        ->set('prorationAmountMinor', 1000)
        ->set('prorationRemainingDays', 15)
        ->set('prorationPeriodDays', 30)
        ->call('calculateProration')
        ->assertHasNoErrors()
        ->assertSet('prorationResult', 500)
        ->set('selectedPlanId', $plan->getKey())
        ->call('captureSnapshot')
        ->assertHasNoErrors()
        ->set('usageMeterId', 801)
        ->set('usageStart', '2026-08-01 00:00:00')
        ->set('usageEnd', '2026-08-31 23:59:59')
        ->call('calculateUsage')
        ->assertHasNoErrors()
        ->assertSet('usageResult.amount_minor', 100)
        ->set('selectedDiscountId', $discount->getKey())
        ->call('redeemDiscount')
        ->assertHasNoErrors();

    expect(PricingSnapshot::query()->where('pricing_plan_id', $plan->getKey())->count())->toBe(1)
        ->and($discount->fresh()->redemptions)->toBe(1);
});
