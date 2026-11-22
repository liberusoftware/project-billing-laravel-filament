<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Communications\Contracts\VoiceProvider;
use Liberu\Billing\Communications\Livewire\Components\CommunicationInventory;
use Liberu\Billing\Communications\Livewire\Components\CommunicationProviders;
use Liberu\Billing\Communications\Livewire\Components\CommunicationServices;
use Liberu\Billing\Communications\Livewire\Components\VoiceAccounts;
use Liberu\Billing\Communications\Models\CommunicationNumber;
use Liberu\Billing\Communications\Models\CommunicationService;
use Liberu\Billing\Communications\Models\VoipAccount;
use Liberu\Billing\Communications\Services\VoiceProviderRegistry;
use Livewire\Livewire;

it('manages communications services, inventory, providers, and voice accounts via Livewire', function (): void {
    app(VoiceProviderRegistry::class)->register(new class() implements VoiceProvider
    {
        public function key(): string
        {
            return 'lw-sip';
        }

        public function provision(array $account): array
        {
            return ['provisioned' => true];
        }
    });

    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.communications.read', 'billing.communications.write']);

    $services = Livewire::test(CommunicationServices::class)
        ->set('name', 'LW Service')
        ->call('createService')
        ->assertHasNoErrors();
    $service = CommunicationService::query()->where('name', 'LW Service')->sole();
    $services->set('selectedServiceId', $service->id)
        ->set('status', 'suspended')
        ->call('transitionService')
        ->assertHasNoErrors();
    expect($service->fresh()->status)->toBe('suspended');

    $inventory = Livewire::test(CommunicationInventory::class)
        ->set('number', '+15551112222')
        ->set('type', 'phone')
        ->call('provision')
        ->assertHasNoErrors();
    $number = CommunicationNumber::query()->where('number', '+15551112222')->sole();
    $inventory->set('selectedNumberId', $number->id)
        ->set('status', 'suspended')
        ->call('transitionNumber')
        ->assertHasNoErrors();
    expect($number->fresh()->status)->toBe('suspended');

    Livewire::test(CommunicationProviders::class)
        ->set('name', 'LW Provider')
        ->set('driver', 'twilio')
        ->call('createProvider')
        ->assertHasNoErrors()
        ->set('provider', 'twilio')
        ->set('rows', 5)
        ->set('totalAmountMinor', 250)
        ->set('currency', 'USD')
        ->call('importUsage')
        ->assertHasNoErrors();

    $voice = Livewire::test(VoiceAccounts::class)
        ->set('customerId', '1')
        ->set('platform', 'lw-sip')
        ->set('sipUsername', 'lw-sip-user')
        ->set('sipSecret', 'lw-secret')
        ->call('create')
        ->assertHasNoErrors();
    $account = VoipAccount::query()->where('sip_username', 'lw-sip-user')->sole();
    $voice->call('provision', $account->id)->assertHasNoErrors();
    expect($account->fresh()->status)->toBe('active');
});
