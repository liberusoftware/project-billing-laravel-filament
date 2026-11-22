<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Catalog\Actions\CreateCatalogRecord;
use Liberu\Billing\Catalog\Actions\CreateProduct;
use Liberu\Billing\Catalog\Actions\TransitionCatalogLifecycle;
use Liberu\Billing\Catalog\Actions\TransitionProductLifecycle;
use Liberu\Billing\Catalog\Enums\CatalogStatus;
use Liberu\Billing\Catalog\Enums\ProductStatus;
use Liberu\Billing\Catalog\Events\CatalogRecordCreated;
use Liberu\Billing\Catalog\Events\CatalogRecordStatusChanged;
use Liberu\Billing\Catalog\Events\ProductCreated;
use Liberu\Billing\Catalog\Events\ProductStatusChanged;
use Liberu\Billing\Catalog\Models\Plan;
use Liberu\Billing\Catalog\Models\Product;
use Liberu\Billing\Catalog\Queries\ListCatalogRecords;
use Liberu\Billing\Catalog\Queries\ListProducts;

it('transitions products and catalog records through lifecycle rules and dispatches events', function (): void {
    Event::fake([
        ProductCreated::class,
        ProductStatusChanged::class,
        CatalogRecordCreated::class,
        CatalogRecordStatusChanged::class,
    ]);

    $product = app(CreateProduct::class)->execute([
        'team_id' => 10,
        'name' => 'Pro Hosting',
        'sku' => 'pro-host',
        'base_price_minor' => 2500,
        'currency' => 'usd',
    ]);

    $activeProduct = app(TransitionProductLifecycle::class)->execute($product, ProductStatus::Active);
    expect($activeProduct->status)->toBe(ProductStatus::Active);

    $archivedProduct = app(TransitionProductLifecycle::class)->execute($activeProduct, ProductStatus::Archived);
    expect($archivedProduct->status)->toBe(ProductStatus::Archived);

    expect(fn () => app(TransitionProductLifecycle::class)->execute($archivedProduct, ProductStatus::Active))
        ->toThrow(InvalidArgumentException::class, 'An archived product cannot be reopened.');

    $plan = app(CreateCatalogRecord::class)->execute(Plan::class, [
        'team_id' => 10,
        'name' => 'Starter Plan',
        'code' => 'starter',
    ]);

    expect(fn () => app(TransitionCatalogLifecycle::class)->execute($plan, CatalogStatus::Archived))
        ->toThrow(ValidationException::class);

    $activePlan = app(TransitionCatalogLifecycle::class)->execute($plan, CatalogStatus::Active);
    expect($activePlan->status)->toBe(CatalogStatus::Active);

    $archivedPlan = app(TransitionCatalogLifecycle::class)->execute($activePlan, CatalogStatus::Archived);
    expect($archivedPlan->status)->toBe(CatalogStatus::Archived);

    expect(fn () => app(TransitionCatalogLifecycle::class)->execute($archivedPlan, CatalogStatus::Active))
        ->toThrow(ValidationException::class);

    Event::assertDispatched(ProductCreated::class);
    Event::assertDispatched(ProductStatusChanged::class, 2);
    Event::assertDispatched(CatalogRecordCreated::class);
    Event::assertDispatched(CatalogRecordStatusChanged::class, 2);
});

it('lists shared and team-scoped products and catalog records while hiding archived products', function (): void {
    $sharedProduct = app(CreateProduct::class)->execute([
        'name' => 'Shared',
        'sku' => 'SHARED-1',
        'base_price_minor' => 100,
        'currency' => 'USD',
    ]);
    $ownProduct = app(CreateProduct::class)->execute([
        'team_id' => 10,
        'name' => 'Own',
        'sku' => 'OWN-1',
        'base_price_minor' => 200,
        'currency' => 'USD',
    ]);
    $archivedProduct = app(CreateProduct::class)->execute([
        'team_id' => 10,
        'name' => 'Archived',
        'sku' => 'ARCH-1',
        'base_price_minor' => 300,
        'currency' => 'USD',
    ]);
    app(TransitionProductLifecycle::class)->execute($archivedProduct, ProductStatus::Archived);
    app(CreateProduct::class)->execute([
        'team_id' => 20,
        'name' => 'Foreign',
        'sku' => 'FOR-1',
        'base_price_minor' => 400,
        'currency' => 'USD',
    ]);

    expect(app(ListProducts::class)->execute(null)->pluck('id')->all())->toBe([$sharedProduct->id])
        ->and(app(ListProducts::class)->execute(10)->pluck('id')->all())->toBe([$ownProduct->id, $sharedProduct->id]);

    $sharedPlan = app(CreateCatalogRecord::class)->execute(Plan::class, ['name' => 'Shared Plan', 'code' => 'SH']);
    $ownPlan = app(CreateCatalogRecord::class)->execute(Plan::class, ['team_id' => 10, 'name' => 'Own Plan', 'code' => 'OWN']);
    app(CreateCatalogRecord::class)->execute(Plan::class, ['team_id' => 20, 'name' => 'Foreign Plan', 'code' => 'FOR']);

    expect(app(ListCatalogRecords::class)->execute(Plan::class, null)->pluck('id')->all())->toBe([$sharedPlan->id])
        ->and(app(ListCatalogRecords::class)->execute(Plan::class, 10)->pluck('id')->all())->toBe([$ownPlan->id, $sharedPlan->id]);
});

it('enforces read and write policies for products and catalog records', function (): void {
    $actor = new User();
    $actor->forceFill(['current_team_id' => 10]);

    $ownProduct = new Product(['team_id' => 10]);
    $foreignProduct = new Product(['team_id' => 20]);
    $ownPlan = new Plan(['team_id' => 10]);
    $foreignPlan = new Plan(['team_id' => 20]);

    Sanctum::actingAs($actor, ['billing.catalog.read']);

    expect(Gate::allows('viewAny', Product::class))->toBeTrue()
        ->and(Gate::allows('view', $ownProduct))->toBeTrue()
        ->and(Gate::denies('view', $foreignProduct))->toBeTrue()
        ->and(Gate::denies('create', Product::class))->toBeTrue()
        ->and(Gate::allows('viewAny', Plan::class))->toBeTrue()
        ->and(Gate::allows('view', $ownPlan))->toBeTrue()
        ->and(Gate::denies('view', $foreignPlan))->toBeTrue()
        ->and(Gate::denies('create', Plan::class))->toBeTrue();

    Sanctum::actingAs($actor, ['billing.catalog.write']);

    expect(Gate::allows('create', Product::class))->toBeTrue()
        ->and(Gate::allows('update', $ownProduct))->toBeTrue()
        ->and(Gate::denies('update', $foreignProduct))->toBeTrue()
        ->and(Gate::allows('create', Plan::class))->toBeTrue()
        ->and(Gate::allows('update', $ownPlan))->toBeTrue()
        ->and(Gate::denies('update', $foreignPlan))->toBeTrue();
});
