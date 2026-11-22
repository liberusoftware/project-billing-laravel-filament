<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Hosting\Actions\CreateHostingAccount as CreateHostingAccountAction;
use Liberu\Billing\Hosting\Contracts\HostingDriver;
use Liberu\Billing\Hosting\Filament\Resources\HostingAccountResource\Pages\CreateHostingAccount;
use Liberu\Billing\Hosting\Filament\Resources\HostingAccountResource\Pages\ListHostingAccounts;
use Liberu\Billing\Hosting\Filament\Resources\HostingCapabilityResource\Pages\CreateHostingCapability;
use Liberu\Billing\Hosting\Filament\Resources\HostingCapabilityResource\Pages\ListHostingCapabilities;
use Liberu\Billing\Hosting\Models\HostingAccount;
use Liberu\Billing\Hosting\Models\HostingCapability;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;
use Livewire\Livewire;

it('manages hosting accounts and capabilities through Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.hosting.read', 'billing.hosting.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    app(HostingDriverRegistry::class)->register(new class() implements HostingDriver
    {
        public function key(): string
        {
            return 'filament-hosting-driver';
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

    Livewire::test(CreateHostingAccount::class)->fillForm([
        'name' => 'Filament Site',
        'status' => 'pending',
    ])->call('create')->assertHasNoFormErrors();

    $account = HostingAccount::query()->where('name', 'Filament Site')->sole();
    $account->update(['metadata' => ['driver' => 'filament-hosting-driver']]);

    $foreign = app(CreateHostingAccountAction::class)->handle($actor->current_team_id + 1, ['name' => 'Foreign Site']);

    Livewire::test(ListHostingAccounts::class)
        ->assertCanSeeTableRecords([$account])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('operation', $account, data: ['operation' => 'provision'])
        ->callTableAction('transition', $account, data: ['status' => 'suspended']);

    expect($account->fresh()->status)->toBe('suspended');

    Livewire::test(CreateHostingCapability::class)->fillForm([
        'type' => 'plan',
        'name' => 'Enterprise Plan',
        'provider' => 'cpanel',
    ])->call('create')->assertHasNoFormErrors();

    $capability = HostingCapability::query()->where('name', 'Enterprise Plan')->sole();

    Livewire::test(ListHostingCapabilities::class)
        ->assertCanSeeTableRecords([$capability])
        ->callTableAction('transition', $capability, data: ['status' => 'active']);

    expect($capability->fresh()->status)->toBe('active');
});
