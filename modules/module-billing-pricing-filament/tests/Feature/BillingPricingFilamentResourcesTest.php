<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Pricing\Actions\CreatePricingPlan as CreatePricingPlanAction;
use Liberu\Billing\Pricing\Filament\Resources\PricingContractResource\Pages\CreatePricingContract;
use Liberu\Billing\Pricing\Filament\Resources\PricingContractResource\Pages\ListPricingContracts;
use Liberu\Billing\Pricing\Filament\Resources\PricingDiscountResource;
use Liberu\Billing\Pricing\Filament\Resources\PricingPlanResource;
use Liberu\Billing\Pricing\Filament\Resources\PricingPlanResource\Pages\CreatePricingPlan;
use Liberu\Billing\Pricing\Filament\Resources\PricingPlanResource\Pages\ListPricingPlans;
use Liberu\Billing\Pricing\Filament\Resources\PricingSnapshotResource;
use Liberu\Billing\Pricing\Models\PricingContract;
use Liberu\Billing\Pricing\Models\PricingDiscount;
use Liberu\Billing\Pricing\Models\PricingPlan;
use Liberu\Billing\Pricing\Models\PricingSnapshot;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates and manages pricing plans, contracts, discounts, and snapshots through Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.pricing.read', 'billing.pricing.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    // Create a usage plan via Filament
    Livewire::test(CreatePricingPlan::class)->fillForm([
        'name' => 'Filament Usage Plan',
        'pricing_model' => 'usage',
        'unit_amount_minor' => 25,
        'currency' => 'USD',
        'usage_unit' => 'call',
    ])->call('create')->assertHasNoFormErrors();

    $plan = PricingPlan::query()->where('name', 'Filament Usage Plan')->sole();
    $foreign = app(CreatePricingPlanAction::class)->execute([
        'name' => 'Foreign Plan',
        'pricing_model' => 'one_time',
        'currency' => 'USD',
        'unit_amount_minor' => 100,
        'team_id' => $actor->current_team_id + 1,
    ]);

    DB::table('billing_usage_meters')->insert([
        'id' => 701,
        'team_id' => $actor->current_team_id,
        'name' => 'Filament Meter',
        'code' => 'filament-meter-701',
        'unit' => 'call',
        'unit_price_minor' => 25,
        'currency' => 'USD',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('billing_usage_records')->insert([
        'team_id' => $actor->current_team_id,
        'customer_id' => null,
        'meter_id' => 701,
        'event_key' => 'filament-usage-1',
        'quantity' => 4,
        'unit_price_minor' => 25,
        'amount_minor' => 100,
        'currency' => 'USD',
        'occurred_at' => '2026-08-10 12:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // List plans and invoke snapshot + usage table actions
    Livewire::test(ListPricingPlans::class)
        ->assertCanSeeTableRecords([$plan])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('snapshot', $plan)
        ->callTableAction('usage', $plan, data: [
            'meter_id' => 701,
            'start' => '2026-08-01 00:00:00',
            'end' => '2026-08-31 23:59:59',
        ])
        ->assertNotified('Usage price: 100');

    expect(PricingSnapshot::query()->where('pricing_plan_id', $plan->getKey())->count())->toBe(1);

    // Create and list pricing contracts
    Livewire::test(CreatePricingContract::class)->fillForm([
        'pricing_plan_id' => $plan->getKey(),
        'starts_at' => '2026-08-01 00:00:00',
        'status' => 'active',
    ])->call('create')->assertHasNoFormErrors();

    $contract = PricingContract::query()->where('pricing_plan_id', $plan->getKey())->sole();
    Livewire::test(ListPricingContracts::class)->assertCanSeeTableRecords([$contract]);

    // Verify PricingSnapshotResource and PricingDiscountResource tables & actions
    $discount = PricingDiscount::query()->create([
        'team_id' => $actor->current_team_id,
        'code' => 'FILAMENT10',
        'kind' => 'percentage',
        'value' => 10,
        'active' => true,
    ]);

    $hasTable = new ListPricingPlans();
    $snapshotTable = PricingSnapshotResource::table(Table::make($hasTable));
    expect(array_keys($snapshotTable->getColumns()))->toBe(['pricing_plan_id', 'version', 'captured_at']);

    $discountTable = PricingDiscountResource::table(Table::make($hasTable));
    $redeemAction = $discountTable->getAction('redeem');
    expect($redeemAction)->not->toBeNull();
    $redeemAction->record($discount);
    expect($redeemAction->isVisible())->toBeTrue();
    $redeemAction->call();

    expect($discount->fresh()->redemptions)->toBe(1);
});

it('restricts the Filament pricing query to shared records without a current team', function (): void {
    $shared = app(CreatePricingPlanAction::class)->execute([
        'name' => 'Shared Plan',
        'pricing_model' => 'one_time',
        'currency' => 'USD',
        'unit_amount_minor' => 100,
    ]);
    app(CreatePricingPlanAction::class)->execute([
        'name' => 'Private Plan',
        'pricing_model' => 'one_time',
        'currency' => 'USD',
        'unit_amount_minor' => 200,
        'team_id' => 42,
    ]);

    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    expect(PricingPlanResource::getEloquentQuery()->pluck('id')->all())->toBe([$shared->getKey()]);
});
