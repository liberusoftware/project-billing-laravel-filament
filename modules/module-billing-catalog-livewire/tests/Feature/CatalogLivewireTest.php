<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Catalog\Enums\CatalogStatus;
use Liberu\Billing\Catalog\Enums\ProductStatus;
use Liberu\Billing\Catalog\Livewire\Components\CatalogRecords;
use Liberu\Billing\Catalog\Livewire\Components\ProductCatalog;
use Liberu\Billing\Catalog\Models\Plan;
use Liberu\Billing\Catalog\Models\Product;
use Livewire\Livewire;

it('creates and transitions products and catalog records via Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.catalog.read', 'billing.catalog.write']);

    $products = Livewire::test(ProductCatalog::class)
        ->set('showCreate', true)
        ->set('name', 'Livewire Product')
        ->set('sku', 'LW-PROD')
        ->set('currency', 'USD')
        ->set('basePriceMinor', 1200)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false);

    $product = Product::query()->where('sku', 'LW-PROD')->sole();

    $products->set('selectedProductId', $product->id)
        ->set('status', 'active')
        ->call('transitionProduct')
        ->assertHasNoErrors();

    expect($product->fresh()->status)->toBe(ProductStatus::Active);

    $records = Livewire::test(CatalogRecords::class)
        ->set('type', 'plans')
        ->set('showCreate', true)
        ->set('name', 'Livewire Plan')
        ->set('code', 'LW-PLAN')
        ->set('description', 'Monthly plan')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false);

    $plan = Plan::query()->where('code', 'LW-PLAN')->sole();

    $records->set('transitionStatus', 'active')
        ->call('transitionRecord', $plan->id)
        ->assertHasNoErrors();

    expect($plan->fresh()->status)->toBe(CatalogStatus::Active);
});
