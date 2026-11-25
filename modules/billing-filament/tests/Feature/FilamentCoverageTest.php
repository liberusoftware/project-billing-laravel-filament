<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Filament\Admin\Resources\MenuResource;
use App\Filament\Admin\Resources\MenuResource\Pages\CreateMenu;
use App\Filament\Admin\Resources\MenuResource\Pages\EditMenu;
use App\Filament\Admin\Resources\MenuResource\Pages\ListMenus;
use App\Filament\App\Widgets\SocialLinksWidget;
use App\Filament\Client\Pages\Dashboard as ClientDashboard;
use App\Filament\Client\Widgets\ExpiringDomains;
use App\Filament\Client\Widgets\HostingOverview;
use App\Filament\Pages\ManageSubscriptionPage;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Products_Service;
use App\Models\Subscription;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);
});

it('renders MenuResource list, create, and edit pages', function (): void {
    $parent = Menu::create([
        'name' => 'Docs',
        'url' => 'https://example.test/docs',
        'order' => 1,
    ]);
    $child = Menu::create([
        'name' => 'API',
        'url' => 'https://example.test/docs/api',
        'parent_id' => $parent->id,
        'order' => 2,
    ]);

    expect(MenuResource::getRelations())->toBe([])
        ->and(MenuResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    Livewire::test(ListMenus::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$parent, $child]);

    Livewire::test(CreateMenu::class)
        ->assertOk()
        ->fillForm([
            'name' => 'Support',
            'url' => 'https://example.test/support',
            'parent_id' => $parent->id,
            'order' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(EditMenu::class, ['record' => $child->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'name' => 'API Reference',
            'url' => 'https://example.test/docs/api-v2',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($child->fresh()->name)->toBe('API Reference');
});

it('renders SocialLinksWidget with configured links and fallback links', function (): void {
    $settings = app(GeneralSettings::class);
    $settings->github_url = 'https://github.com/liberu';
    $settings->facebook_url = 'https://facebook.com/liberu';
    $settings->twitter_url = 'https://twitter.com/liberu';
    $settings->youtube_url = 'https://youtube.com/liberu';
    $settings->save();

    $widget = new SocialLinksWidget();
    $view = $widget->render();
    expect($view->getData()['links'])->toBe([
        'GitHub' => 'https://github.com/liberu',
        'Facebook' => 'https://facebook.com/liberu',
        'Twitter' => 'https://twitter.com/liberu',
        'YouTube' => 'https://youtube.com/liberu',
    ]);

    $settings->github_url = null;
    $settings->facebook_url = null;
    $settings->twitter_url = null;
    $settings->youtube_url = null;
    $settings->save();

    $fallbackView = $widget->render();
    expect($fallbackView->getData()['links'])->toHaveKeys(['GitHub', 'Facebook Page', 'Facebook Groups']);
});

it('returns expected widgets and heading from Client Dashboard', function (): void {
    $dashboard = new ClientDashboard();

    expect($dashboard->getWidgets())->toBe([HostingOverview::class, ExpiringDomains::class])
        ->and($dashboard->getHeading())->toBe('Your hosting & domains');
});

it('mounts, saves, cancels, and resumes subscriptions on ManageSubscriptionPage', function (): void {
    $user = auth()->user();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $product = Products_Service::factory()->create(['price' => 49.99]);

    // Mount without existing subscription, create new subscription with Custom cycle
    Livewire::test(ManageSubscriptionPage::class)
        ->assertOk()
        ->set('selectedProduct', $product->id)
        ->set('renewalPeriod', BillingCycle::Custom->value)
        ->set('customPeriodDays', 45)
        ->set('startDate', '2026-07-01')
        ->set('autoRenew', true)
        ->call('save')
        ->assertNotified('Subscription updated successfully');

    $subscription = Subscription::where('customer_id', $customer->id)->first();
    expect($subscription)->not->toBeNull()
        ->and($subscription->custom_period_days)->toBe(45)
        ->and((bool) $subscription->auto_renew)->toBeTrue();

    // Mount with existing subscription, switch to OneTime, then cancel & resume
    $user->refresh();
    Livewire::test(ManageSubscriptionPage::class)
        ->assertSet('selectedProduct', $product->id)
        ->set('renewalPeriod', BillingCycle::OneTime->value)
        ->set('startDate', '2026-07-02')
        ->call('save')
        ->assertNotified('Subscription updated successfully')
        ->call('cancel')
        ->assertNotified('Subscription cancelled successfully')
        ->call('resume')
        ->assertNotified('Subscription resumed successfully');
});
