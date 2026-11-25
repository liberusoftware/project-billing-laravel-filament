<?php

declare(strict_types=1);

use App\Filament\App\Resources\ProductsServices\Pages\CreateProductsService;
use App\Filament\App\Resources\ProductsServices\Pages\EditProductsService;
use App\Filament\App\Resources\ProductsServices\Pages\ListProductsServices;
use App\Models\Products_Service;
use App\Models\Tld;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

it('renders the products services list page and table actions', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($user->currentTeam->id);

    foreach ([
        'view_any_products::service',
        'view_products::service',
        'create_products::service',
        'update_products::service',
        'delete_products::service',
        'delete_any_products::service',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $this->actingAs($user);
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($user->currentTeam);

    $product = Products_Service::factory()->create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Shared Hosting',
        'type' => 'hosting',
        'pricing_model' => 'fixed',
        'base_price' => 9.99,
    ]);

    Livewire::test(ListProductsServices::class)
        ->assertSuccessful()
        ->assertActionExists('create')
        ->assertCanSeeTableRecords([$product]);
});

it('validates conditional form fields and creates and edits products services', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($user->currentTeam->id);

    foreach ([
        'view_any_products::service',
        'view_products::service',
        'create_products::service',
        'update_products::service',
        'delete_products::service',
        'delete_any_products::service',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $this->actingAs($user);
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($user->currentTeam);

    Tld::create([
        'name' => '.com',
        'base_price' => 12.00,
        'enom_cost' => 9.00,
        'markup_type' => 'fixed',
        'markup_value' => 3.00,
    ]);

    Livewire::test(CreateProductsService::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Domain Registration',
            'description' => 'Register a .com domain',
            'base_price' => 14.99,
            'type' => 'domain',
            'pricing_model' => 'tiered',
            'tld_id' => null,
            'markup_type' => null,
            'markup_value' => null,
        ])
        ->assertFormFieldIsVisible('custom_pricing_data')
        ->assertFormFieldIsVisible('tld_id')
        ->assertFormFieldIsVisible('markup_type')
        ->assertFormFieldIsVisible('markup_value')
        ->call('create')
        ->assertHasFormErrors([
            'tld_id' => 'required',
            'markup_type' => 'required',
            'markup_value' => 'required',
        ]);

    Livewire::test(CreateProductsService::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Managed Hosting',
            'description' => 'Fast managed hosting',
            'base_price' => 29.99,
            'type' => 'hosting',
            'pricing_model' => 'fixed',
        ])
        ->assertFormFieldIsHidden('custom_pricing_data')
        ->assertFormFieldIsHidden('tld_id')
        ->assertFormFieldIsHidden('markup_type')
        ->assertFormFieldIsHidden('markup_value')
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Products_Service::query()->where('name', 'Managed Hosting')->firstOrFail();

    Livewire::test(EditProductsService::class, ['record' => $product->getRouteKey()])
        ->assertSuccessful()
        ->assertActionExists('delete')
        ->assertFormSet([
            'name' => 'Managed Hosting',
            'type' => 'hosting',
            'pricing_model' => 'fixed',
        ])
        ->fillForm([
            'name' => 'Updated Hosting',
            'pricing_model' => 'usage_based',
            'custom_pricing_data' => ['bandwidth_gb' => '0.50'],
        ])
        ->assertFormFieldIsVisible('custom_pricing_data')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->name)->toBe('Updated Hosting')
        ->and($product->fresh()->pricing_model)->toBe('usage_based');
});
