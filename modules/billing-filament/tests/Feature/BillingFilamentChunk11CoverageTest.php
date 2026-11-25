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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    setPermissionsTeamId($team->id);
    Role::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
        'team_id' => $team->id,
    ]);
    $user->assignRole('super_admin');

    $this->actingAs($user);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);
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

    expect(Menu::where('name', 'Support')
        ->where('parent_id', $parent->id)
        ->where('order', 3)
        ->exists())->toBeTrue();

    Livewire::test(EditMenu::class, ['record' => $child->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'name' => 'API Reference',
            'url' => 'https://example.test/docs/api-v2',
            'parent_id' => $parent->id,
            'order' => 4,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($child->fresh()->name)->toBe('API Reference')
        ->and($child->fresh()->url)->toBe('https://example.test/docs/api-v2');
});

it('renders SocialLinksWidget with configured links and fallback links', function (): void {
    $tempViewDir = sys_get_temp_dir().'/billing-chunk11-social-'.uniqid('', true);
    $viewFile = $tempViewDir.'/filament/app/widgets/social-links-widget.blade.php';
    File::ensureDirectoryExists(dirname($viewFile));
    File::put($viewFile, '<div></div>');

    $finder = View::getFinder();
    $originalPaths = $finder->getPaths();
    View::addLocation($tempViewDir);
    $finder->flush();

    try {
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

        expect($fallbackView->getData()['links'])->toBe([
            'GitHub' => 'https://www.github.com/liberu-genealogy',
            'Facebook Page' => 'https://www.facebook.com/familytree365',
            'Facebook Groups' => [
                'Family Tree 365' => 'https://www.facebook.com/groups/familytree365',
                'Genealogy Chat' => 'https://www.facebook.com/groups/genealogychat',
                'DNA 365' => 'https://www.facebook.com/groups/dna365',
            ],
        ]);
    } finally {
        $finder->setPaths($originalPaths);
        $finder->flush();
        File::deleteDirectory($tempViewDir);
    }
});

it('returns expected widgets and heading from Client Dashboard', function (): void {
    $dashboard = new ClientDashboard();

    expect($dashboard->getWidgets())->toBe([HostingOverview::class, ExpiringDomains::class])
        ->and($dashboard->getHeading())->toBe('Your hosting & domains');
});

it('mounts, renders form, saves, cancels, and resumes subscriptions on ManageSubscriptionPage', function (): void {
    $tempViewDir = sys_get_temp_dir().'/billing-chunk11-sub-'.uniqid('', true);
    $viewFile = $tempViewDir.'/filament/pages/manage-subscription.blade.php';
    File::ensureDirectoryExists(dirname($viewFile));
    File::put($viewFile, '<div>{{ $this->form }}</div>');

    $finder = View::getFinder();
    $originalPaths = $finder->getPaths();
    View::addLocation($tempViewDir);
    $finder->flush();

    try {
        /** @var User $user */
        $user = auth()->user();
        $customer = Customer::factory()->create(['user_id' => $user->id]);
        $product = Products_Service::factory()->create(['base_price' => 49.99]);

        // Mount without existing subscription; create one using Custom billing cycle.
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
            ->and($subscription->product_service_id)->toBe($product->id)
            ->and($subscription->renewal_period)->toBe(BillingCycle::Custom->value)
            ->and($subscription->custom_period_days)->toBe(45)
            ->and((bool) $subscription->auto_renew)->toBeTrue()
            ->and((float) $subscription->price)->toBe(49.99)
            ->and($subscription->currency)->toBe('USD')
            ->and($subscription->status)->toBe('active');

        // Mount with existing subscription; switch to OneTime cycle and cancel.
        $user->refresh();
        Livewire::test(ManageSubscriptionPage::class)
            ->assertOk()
            ->assertSet('selectedProduct', $product->id)
            ->assertSet('renewalPeriod', BillingCycle::Custom->value)
            ->assertSet('customPeriodDays', 45)
            ->set('renewalPeriod', BillingCycle::OneTime->value)
            ->set('startDate', '2026-07-02')
            ->call('save')
            ->assertNotified('Subscription updated successfully')
            ->call('cancel')
            ->assertNotified('Subscription cancelled successfully');

        $subscription->refresh();
        expect($subscription->renewal_period)->toBe(BillingCycle::OneTime->value)
            ->and($subscription->custom_period_days)->toBeNull()
            ->and((bool) $subscription->auto_renew)->toBeFalse()
            ->and($subscription->status)->toBe('cancelled');

        // Suspend and resume the subscription via ManageSubscriptionPage.
        $subscription->suspend();
        $user->refresh();

        Livewire::test(ManageSubscriptionPage::class)
            ->assertOk()
            ->call('resume')
            ->assertNotified('Subscription resumed successfully');

        expect($subscription->fresh()->status)->toBe('active');
    } finally {
        $finder->setPaths($originalPaths);
        $finder->flush();
        File::deleteDirectory($tempViewDir);
    }
});
