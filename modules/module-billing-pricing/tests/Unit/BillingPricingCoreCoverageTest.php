<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Billing\Pricing\Actions\CalculatePricingPlanAmount;
use Liberu\Billing\Pricing\Actions\CalculateProration;
use Liberu\Billing\Pricing\Actions\CapturePricingSnapshot;
use Liberu\Billing\Pricing\Actions\CreatePricingContract;
use Liberu\Billing\Pricing\Actions\CreatePricingPlan;
use Liberu\Billing\Pricing\Actions\RedeemPricingDiscount;
use Liberu\Billing\Pricing\Events\PricingDiscountRedeemed;
use Liberu\Billing\Pricing\Models\PricingDiscount;
use Liberu\Billing\Pricing\Policies\PricingPlanPolicy;
use Liberu\Billing\Pricing\Policies\PricingRecordPolicy;
use Liberu\Billing\Pricing\Queries\ListPricingPlans;
use Liberu\Billing\Pricing\Support\CustomerReference;

uses(RefreshDatabase::class);

it('calculates proration and rejects invalid proration inputs', function (): void {
    $proration = app(CalculateProration::class);

    expect($proration->execute(1000, 15, 30))->toBe(500)
        ->and($proration->execute(0, 0, 30))->toBe(0);

    expect(fn () => $proration->execute(-1, 5, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $proration->execute(100, -1, 30))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $proration->execute(100, 5, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $proration->execute(100, 31, 30))->toThrow(InvalidArgumentException::class);
});

it('rejects empty tiers and non-array tier items when calculating tiered plan amounts', function (): void {
    $plan = app(CreatePricingPlan::class)->execute([
        'name' => 'Tiered',
        'pricing_model' => 'tiered',
        'currency' => 'USD',
        'unit_amount_minor' => 0,
        'tiers' => [['up_to' => 10, 'unit_amount_minor' => 100]],
    ]);
    $calculate = app(CalculatePricingPlanAmount::class);

    $plan->tiers = [];
    expect(fn () => $calculate->execute($plan, ['quantity' => 5]))
        ->toThrow(InvalidArgumentException::class, 'Tiered pricing requires tier definitions.');

    $plan->tiers = ['not-an-array'];
    expect(fn () => $calculate->execute($plan, ['quantity' => 5]))
        ->toThrow(InvalidArgumentException::class, 'Pricing tiers must be arrays.');
});

it('retries snapshot capture on unique constraint collision and rethrows non-unique or exhausted errors', function (): void {
    $plan = app(CreatePricingPlan::class)->execute([
        'name' => 'Retry plan',
        'pricing_model' => 'one_time',
        'currency' => 'USD',
        'unit_amount_minor' => 500,
    ]);

    $realDb = app(DatabaseManager::class);
    $uniqueException = new QueryException('sqlite', 'insert into billing_pricing_snapshots', [], new RuntimeException('UNIQUE constraint failed'));
    $otherException = new QueryException('sqlite', 'insert into billing_pricing_snapshots', [], new RuntimeException('database is locked'));

    $attempt = 0;
    $retryDb = Mockery::mock(DatabaseManager::class);
    $retryDb->shouldReceive('transaction')->twice()->andReturnUsing(function (Closure $callback) use (&$attempt, $uniqueException, $realDb) {
        $attempt++;
        if ($attempt === 1) {
            throw $uniqueException;
        }

        return $realDb->transaction($callback);
    });

    $snapshot = (new CapturePricingSnapshot($retryDb))->execute($plan);
    expect($snapshot->version)->toBe(1);

    $failDb = Mockery::mock(DatabaseManager::class);
    $failDb->shouldReceive('transaction')->once()->andThrow($otherException);

    expect(fn () => (new CapturePricingSnapshot($failDb))->execute($plan))
        ->toThrow(QueryException::class);
});

it('validates missing or foreign plans when creating pricing contracts and handles null or invalid customer references', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherPlan = app(CreatePricingPlan::class)->execute([
        'team_id' => $otherTeam->getKey(),
        'name' => 'Other plan',
        'pricing_model' => 'one_time',
        'currency' => 'USD',
        'unit_amount_minor' => 100,
    ]);

    $createContract = app(CreatePricingContract::class);

    expect(fn () => $createContract->execute([
        'team_id' => $team->getKey(),
        'pricing_plan_id' => null,
    ]))->toThrow(InvalidArgumentException::class, 'Pricing plan reference is invalid.');

    expect(fn () => $createContract->execute([
        'team_id' => $team->getKey(),
        'pricing_plan_id' => $otherPlan->getKey(),
    ]))->toThrow(InvalidArgumentException::class, 'Pricing plan reference is invalid.');

    $db = app(DatabaseManager::class);
    expect(CustomerReference::assertBelongsToTeam($db, null, $team->getKey()))->toBeNull()
        ->and(CustomerReference::assertBelongsToTeam($db, '', $team->getKey()))->toBeNull();

    expect(fn () => CustomerReference::assertBelongsToTeam($db, 0, $team->getKey()))
        ->toThrow(InvalidArgumentException::class, 'Pricing customer reference is invalid.');
});

it('rejects product reference when catalog products table is absent', function (): void {
    Schema::shouldReceive('hasTable')
        ->once()
        ->with('billing_catalog_products')
        ->andReturn(false);

    expect(fn () => app(CreatePricingPlan::class)->execute([
        'name' => 'Plan with product',
        'pricing_model' => 'one_time',
        'currency' => 'USD',
        'unit_amount_minor' => 100,
        'product_id' => 1,
    ]))->toThrow(InvalidArgumentException::class, 'Pricing product reference is invalid.');
});

it('redeems active discounts and rejects unredeemable discounts before and inside the transaction lock', function (): void {
    Event::fake([PricingDiscountRedeemed::class]);

    $team = Team::factory()->create();
    $discount = PricingDiscount::query()->create([
        'team_id' => $team->getKey(),
        'code' => 'SAVE20',
        'kind' => 'percentage',
        'value' => 20,
        'max_redemptions' => 2,
        'redemptions' => 0,
        'active' => true,
    ]);

    $redeem = app(RedeemPricingDiscount::class);
    $redeemed = $redeem->execute($discount);

    expect($redeemed->redemptions)->toBe(1);
    Event::assertDispatched(PricingDiscountRedeemed::class);

    $inactive = PricingDiscount::query()->create([
        'team_id' => $team->getKey(),
        'code' => 'INACTIVE',
        'kind' => 'fixed',
        'value' => 100,
        'active' => false,
    ]);
    expect(fn () => $redeem->execute($inactive))
        ->toThrow(LogicException::class, 'This discount is not redeemable.');

    // Simulate concurrent deactivation after the initial in-memory check
    $stale = PricingDiscount::query()->create([
        'team_id' => $team->getKey(),
        'code' => 'RACE',
        'kind' => 'fixed',
        'value' => 100,
        'active' => true,
    ]);
    PricingDiscount::query()->whereKey($stale->getKey())->update(['active' => false]);

    expect(fn () => $redeem->execute($stale))
        ->toThrow(LogicException::class, 'This discount is not redeemable.');
});

it('lists pricing plans scoped by team or shared only', function (): void {
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();

    $create = app(CreatePricingPlan::class);
    $shared = $create->execute(['team_id' => null, 'name' => 'Shared', 'pricing_model' => 'one_time', 'currency' => 'USD', 'unit_amount_minor' => 100]);
    $own = $create->execute(['team_id' => $team->getKey(), 'name' => 'Own', 'pricing_model' => 'one_time', 'currency' => 'USD', 'unit_amount_minor' => 200]);
    $create->execute(['team_id' => $otherTeam->getKey(), 'name' => 'Foreign', 'pricing_model' => 'one_time', 'currency' => 'USD', 'unit_amount_minor' => 300]);

    $query = app(ListPricingPlans::class);

    expect($query->execute(null)->getCollection()->pluck('id')->all())->toBe([$shared->getKey()])
        ->and($query->execute($team->getKey())->getCollection()->pluck('id')->sort()->values()->all())
        ->toBe([$shared->getKey(), $own->getKey()]);
});

it('enforces PricingPlanPolicy and PricingRecordPolicy access rules', function (): void {
    $readUser = new class()
    {
        public int $current_team_id = 10;

        public function tokenCan(string $ability): bool
        {
            return $ability === 'billing.pricing.read';
        }
    };
    $writeUser = new class()
    {
        public int $current_team_id = 10;

        public function tokenCan(string $ability): bool
        {
            return $ability === 'billing.pricing.write';
        }
    };
    $noAccessUser = new class()
    {
        public int $current_team_id = 10;

        public function tokenCan(string $ability): bool
        {
            return false;
        }
    };

    $ownPlan = app(CreatePricingPlan::class)->execute([
        'team_id' => 10, 'name' => 'Own', 'pricing_model' => 'one_time', 'currency' => 'USD', 'unit_amount_minor' => 100,
    ]);
    $foreignPlan = app(CreatePricingPlan::class)->execute([
        'team_id' => 20, 'name' => 'Foreign', 'pricing_model' => 'one_time', 'currency' => 'USD', 'unit_amount_minor' => 100,
    ]);

    $planPolicy = app(PricingPlanPolicy::class);
    expect($planPolicy->viewAny($readUser))->toBeTrue()
        ->and($planPolicy->viewAny($noAccessUser))->toBeFalse()
        ->and($planPolicy->view($readUser, $ownPlan))->toBeTrue()
        ->and($planPolicy->view($readUser, $foreignPlan))->toBeFalse();

    $recordPolicy = app(PricingRecordPolicy::class);
    $ownRecord = (object) ['team_id' => 10];
    $foreignRecord = (object) ['team_id' => 20];
    $sharedRecord = (object) ['team_id' => null];
    $plainUser = User::factory()->withPersonalTeam()->create();

    expect($recordPolicy->viewAny(null))->toBeFalse()
        ->and($recordPolicy->viewAny($readUser))->toBeTrue()
        ->and($recordPolicy->create(null))->toBeFalse()
        ->and($recordPolicy->create($readUser))->toBeFalse()
        ->and($recordPolicy->create($writeUser))->toBeTrue()
        ->and($recordPolicy->create($plainUser))->toBeTrue()
        ->and($recordPolicy->view(null, $ownRecord))->toBeFalse()
        ->and($recordPolicy->view($readUser, $ownRecord))->toBeTrue()
        ->and($recordPolicy->view($readUser, $foreignRecord))->toBeFalse()
        ->and($recordPolicy->view($readUser, $sharedRecord))->toBeTrue()
        ->and($recordPolicy->update($readUser, $ownRecord))->toBeFalse()
        ->and($recordPolicy->update($writeUser, $ownRecord))->toBeTrue()
        ->and($recordPolicy->update($writeUser, $foreignRecord))->toBeFalse()
        ->and($recordPolicy->update($writeUser, $sharedRecord))->toBeTrue();
});
