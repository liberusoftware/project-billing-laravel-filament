<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Hosting\Contracts\HostingDriver;
use Liberu\Billing\Hosting\Livewire\Components\HostingAccounts;
use Liberu\Billing\Hosting\Livewire\Components\HostingCapabilities;
use Liberu\Billing\Hosting\Models\HostingAccount;
use Liberu\Billing\Hosting\Models\HostingCapability;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;
use Livewire\Livewire;

it('manages hosting accounts and capabilities through Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.hosting.read', 'billing.hosting.write']);

    app(HostingDriverRegistry::class)->register(new class() implements HostingDriver
    {
        public function key(): string
        {
            return 'lw-hosting-driver';
        }

        public function provision(array $attributes): array
        {
            return ['ok' => true];
        }

        public function suspend(array $attributes): array
        {
            return ['ok' => true];
        }

        public function terminate(array $attributes): array
        {
            return ['ok' => true];
        }
    });

    $accountsComponent = Livewire::test(HostingAccounts::class)
        ->set('name', 'Livewire Site')
        ->set('status', 'pending')
        ->call('createAccount')
        ->assertHasNoErrors();

    $account = HostingAccount::query()->where('name', 'Livewire Site')->sole();
    $account->update(['metadata' => ['driver' => 'lw-hosting-driver']]);

    $accountsComponent
        ->set('selectedAccountId', $account->id)
        ->set('operation', 'provision')
        ->call('performOperation')
        ->assertHasNoErrors()
        ->set('selectedAccountId', $account->id)
        ->set('status', 'suspended')
        ->call('transitionAccount')
        ->assertHasNoErrors();

    expect($account->fresh()->status)->toBe('suspended');

    $capComponent = Livewire::test(HostingCapabilities::class)
        ->set('type', 'ssl')
        ->set('name', 'DV Cert')
        ->call('createCapability')
        ->assertHasNoErrors();

    $capability = HostingCapability::query()->where('name', 'DV Cert')->sole();

    $capComponent
        ->set('status', 'active')
        ->call('transitionCapability', $capability->id)
        ->assertHasNoErrors();

    expect($capability->fresh()->status)->toBe('active');
});
