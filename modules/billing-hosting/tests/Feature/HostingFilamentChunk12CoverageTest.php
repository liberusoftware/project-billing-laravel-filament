<?php

declare(strict_types=1);

use App\Filament\App\Resources\HostingAccounts\Pages\CreateHostingAccount;
use App\Filament\App\Resources\HostingAccounts\Pages\EditHostingAccount;
use App\Filament\Client\Resources\HostingAccounts\HostingAccountResource as ClientHostingAccountResource;
use App\Filament\Client\Resources\HostingAccounts\Pages\ListHostingAccounts as ClientListHostingAccounts;
use App\Filament\Client\Widgets\HostingOverview;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Products_Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ControlPanels\CpanelClient;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

it('renders app panel create and edit hosting account pages with form and header actions', function (): void {
    $permissions = [
        'view_any_hosting::account',
        'view_hosting::account',
        'create_hosting::account',
        'update_hosting::account',
        'delete_hosting::account',
    ];

    $user = User::factory()->withPersonalTeam()->create();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $this->actingAs($user);
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($user->currentTeam);

    $customer = Customer::factory()->create();
    $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);

    Livewire::test(CreateHostingAccount::class)
        ->assertSuccessful()
        ->assertFormFieldExists('customer_id')
        ->assertFormFieldExists('subscription_id')
        ->assertFormFieldExists('control_panel')
        ->assertFormFieldExists('username')
        ->assertFormFieldExists('domain')
        ->assertFormFieldExists('package')
        ->assertFormFieldExists('status')
        ->fillForm([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'control_panel' => 'cpanel',
            'username' => 'chunk12user',
            'domain' => 'chunk12.example.com',
            'package' => 'standard',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = HostingAccount::query()->where('username', 'chunk12user')->firstOrFail();
    $account->team_id = $user->currentTeam->id;
    $account->save();

    Livewire::test(EditHostingAccount::class, ['record' => $account->getRouteKey()])
        ->assertSuccessful()
        ->assertActionExists(DeleteAction::class)
        ->fillForm([
            'status' => 'suspended',
            'package' => 'premium',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh()->status)->toBe('suspended')
        ->and($account->fresh()->package)->toBe('premium');
});

it('renders client hosting account statuses, allows canView, and notifies when cPanel SSO fails', function (): void {
    $user = User::factory()->create(['email' => 'client-chunk12@example.com']);
    $customer = Customer::factory()->create(['email' => 'client-chunk12@example.com']);

    $activeAccount = HostingAccount::factory()->cpanel()->active()->create([
        'customer_id' => $customer->id,
    ]);
    $suspendedAccount = HostingAccount::factory()->cpanel()->suspended()->create([
        'customer_id' => $customer->id,
    ]);
    $pendingAccount = HostingAccount::factory()->cpanel()->pending()->create([
        'customer_id' => $customer->id,
    ]);

    expect(ClientHostingAccountResource::canView($activeAccount))->toBeTrue();

    $mock = Mockery::mock(CpanelClient::class);
    $mock->shouldReceive('setServer')->once();
    $mock->shouldReceive('createSsoSession')
        ->once()
        ->with($activeAccount->username)
        ->andReturn(null);
    $this->app->instance(CpanelClient::class, $mock);

    $this->actingAs($user);
    $panel = Filament::getPanel('client');
    Filament::setCurrentPanel($panel);
    $panel->boot();

    Livewire::test(ClientListHostingAccounts::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$activeAccount, $suspendedAccount, $pendingAccount])
        ->callAction(TestAction::make('open_cpanel')->table($activeAccount))
        ->assertNotified('Unable to open cPanel session. Please try again later.');
});

it('renders HostingOverview widget with zero when user has no customer and active hosting count otherwise', function (): void {
    $userWithoutCustomer = User::factory()->create();

    $this->actingAs($userWithoutCustomer);
    $panel = Filament::getPanel('client');
    Filament::setCurrentPanel($panel);
    $panel->boot();

    Livewire::test(HostingOverview::class)
        ->assertSuccessful()
        ->assertSee('Active hosting services')
        ->assertSee('0');

    $userWithCustomer = User::factory()->create();
    $customer = Customer::factory()->create(['user_id' => $userWithCustomer->id]);
    $hostingProduct = Products_Service::factory()->create(['type' => 'hosting']);
    $domainProduct = Products_Service::factory()->create(['type' => 'domain']);

    Subscription::factory()->count(2)->create([
        'customer_id' => $customer->id,
        'product_service_id' => $hostingProduct->id,
        'status' => 'active',
    ]);
    Subscription::factory()->create([
        'customer_id' => $customer->id,
        'product_service_id' => $hostingProduct->id,
        'status' => 'cancelled',
    ]);
    Subscription::factory()->create([
        'customer_id' => $customer->id,
        'product_service_id' => $domainProduct->id,
        'status' => 'active',
    ]);

    $this->actingAs($userWithCustomer);

    Livewire::test(HostingOverview::class)
        ->assertSuccessful()
        ->assertSee('Active hosting services')
        ->assertSee('2');
});
