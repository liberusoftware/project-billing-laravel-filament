<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Catalog\Actions\CreateProduct;
use Liberu\Billing\Catalog\Enums\CatalogStatus;
use Liberu\Billing\Catalog\Enums\ProductStatus;
use Liberu\Billing\Catalog\Filament\CatalogFilamentPlugin;
use Liberu\Billing\Catalog\Filament\Resources\AddonResource\Pages\CreateAddon;
use Liberu\Billing\Catalog\Filament\Resources\AddonResource\Pages\ListAddons;
use Liberu\Billing\Catalog\Filament\Resources\BundleResource\Pages\CreateBundle;
use Liberu\Billing\Catalog\Filament\Resources\BundleResource\Pages\ListBundles;
use Liberu\Billing\Catalog\Filament\Resources\ChannelResource\Pages\CreateChannel;
use Liberu\Billing\Catalog\Filament\Resources\ChannelResource\Pages\ListChannels;
use Liberu\Billing\Catalog\Filament\Resources\ConfigurableOptionResource\Pages\CreateConfigurableOption;
use Liberu\Billing\Catalog\Filament\Resources\ConfigurableOptionResource\Pages\ListConfigurableOptions;
use Liberu\Billing\Catalog\Filament\Resources\EligibilityResource\Pages\CreateEligibility;
use Liberu\Billing\Catalog\Filament\Resources\EligibilityResource\Pages\ListEligibility;
use Liberu\Billing\Catalog\Filament\Resources\PlanResource\Pages\CreatePlan;
use Liberu\Billing\Catalog\Filament\Resources\PlanResource\Pages\ListPlans;
use Liberu\Billing\Catalog\Filament\Resources\ProductResource;
use Liberu\Billing\Catalog\Filament\Resources\ProductResource\Pages\CreateProduct as CreateProductPage;
use Liberu\Billing\Catalog\Filament\Resources\ProductResource\Pages\ListProducts;
use Liberu\Billing\Catalog\Models\Addon;
use Liberu\Billing\Catalog\Models\Bundle;
use Liberu\Billing\Catalog\Models\Channel;
use Liberu\Billing\Catalog\Models\ConfigurableOption;
use Liberu\Billing\Catalog\Models\Eligibility;
use Liberu\Billing\Catalog\Models\Plan;
use Liberu\Billing\Catalog\Models\Product;
use Livewire\Livewire;

it('manages catalog products and capability records through Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.catalog.read', 'billing.catalog.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);
    CatalogFilamentPlugin::make()->boot($panel);

    Livewire::test(CreateProductPage::class)->fillForm([
        'name' => 'Filament Product',
        'sku' => 'FIL-PROD-1',
        'base_price_minor' => 1500,
        'currency' => 'USD',
    ])->call('create')->assertHasNoFormErrors();

    $product = Product::query()->where('sku', 'FIL-PROD-1')->sole();
    $product->update(['team_id' => $actor->current_team_id]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$product])
        ->callTableAction('lifecycle', $product, data: ['status' => 'active']);

    expect($product->fresh()->status)->toBe(ProductStatus::Active);

    $resources = [
        [CreatePlan::class, ListPlans::class, Plan::class, 'PLAN-1'],
        [CreateAddon::class, ListAddons::class, Addon::class, 'ADDON-1'],
        [CreateBundle::class, ListBundles::class, Bundle::class, 'BUNDLE-1'],
        [CreateConfigurableOption::class, ListConfigurableOptions::class, ConfigurableOption::class, 'OPT-1'],
        [CreateEligibility::class, ListEligibility::class, Eligibility::class, 'ELIG-1'],
        [CreateChannel::class, ListChannels::class, Channel::class, 'CHAN-1'],
    ];

    foreach ($resources as [$createPage, $listPage, $modelClass, $code]) {
        Livewire::test($createPage)->fillForm([
            'name' => "Record {$code}",
            'code' => $code,
            'description' => 'Created in Filament',
        ])->call('create')->assertHasNoFormErrors();

        $record = $modelClass::query()->where('code', $code)->sole();

        Livewire::test($listPage)
            ->assertCanSeeTableRecords([$record])
            ->callTableAction('transition', $record, data: ['status' => 'active']);

        expect($record->fresh()->status)->toBe(CatalogStatus::Active);
    }
});

it('scopes Filament catalog queries to shared records when no current team is active', function (): void {
    $shared = app(CreateProduct::class)->execute([
        'name' => 'Shared Product',
        'sku' => 'SHARED-FIL',
        'base_price_minor' => 500,
        'currency' => 'USD',
    ]);
    app(CreateProduct::class)->execute([
        'team_id' => 999,
        'name' => 'Private Product',
        'sku' => 'PRIV-FIL',
        'base_price_minor' => 500,
        'currency' => 'USD',
    ]);

    $this->actingAs(User::factory()->create(), 'web');

    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toBe([$shared->id]);
});
