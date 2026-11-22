<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Communications\Contracts\VoiceProvider;
use Liberu\Billing\Communications\Filament\CommunicationsFilamentPlugin;
use Liberu\Billing\Communications\Filament\Resources\CommunicationNumberResource\Pages\CreateCommunicationNumber;
use Liberu\Billing\Communications\Filament\Resources\CommunicationNumberResource\Pages\ListCommunicationNumbers;
use Liberu\Billing\Communications\Filament\Resources\CommunicationProviderResource\Pages\CreateCommunicationProvider;
use Liberu\Billing\Communications\Filament\Resources\CommunicationProviderResource\Pages\ListCommunicationProviders;
use Liberu\Billing\Communications\Filament\Resources\CommunicationServiceResource\Pages\CreateCommunicationService;
use Liberu\Billing\Communications\Filament\Resources\CommunicationServiceResource\Pages\ListCommunicationServices;
use Liberu\Billing\Communications\Filament\Resources\CommunicationUsageImportResource\Pages\CreateCommunicationUsageImport;
use Liberu\Billing\Communications\Filament\Resources\CommunicationUsageImportResource\Pages\ListCommunicationUsageImports;
use Liberu\Billing\Communications\Filament\Resources\VoipAccountResource\Pages\CreateVoipAccount;
use Liberu\Billing\Communications\Filament\Resources\VoipAccountResource\Pages\ListVoipAccounts;
use Liberu\Billing\Communications\Models\CommunicationNumber;
use Liberu\Billing\Communications\Models\CommunicationProvider;
use Liberu\Billing\Communications\Models\CommunicationService;
use Liberu\Billing\Communications\Models\CommunicationUsageImport;
use Liberu\Billing\Communications\Models\VoipAccount;
use Liberu\Billing\Communications\Services\VoiceProviderRegistry;
use Livewire\Livewire;

it('manages communication resources through Filament', function (): void {
    app(VoiceProviderRegistry::class)->register(new class() implements VoiceProvider
    {
        public function key(): string
        {
            return 'filament-sip';
        }

        public function provision(array $account): array
        {
            return ['provisioned' => true];
        }
    });

    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.communications.read', 'billing.communications.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);
    CommunicationsFilamentPlugin::make()->boot($panel);

    Livewire::test(CreateCommunicationService::class)->fillForm([
        'name' => 'Filament SIP Service',
        'status' => 'active',
    ])->call('create')->assertHasNoFormErrors();
    $service = CommunicationService::query()->where('name', 'Filament SIP Service')->sole();
    Livewire::test(ListCommunicationServices::class)
        ->assertCanSeeTableRecords([$service])
        ->callTableAction('transition', $service, data: ['status' => 'suspended']);
    expect($service->fresh()->status)->toBe('suspended');

    Livewire::test(CreateCommunicationNumber::class)->fillForm([
        'number' => '+15559876543',
        'type' => 'did',
    ])->call('create')->assertHasNoFormErrors();
    $number = CommunicationNumber::query()->where('number', '+15559876543')->sole();
    Livewire::test(ListCommunicationNumbers::class)
        ->assertCanSeeTableRecords([$number])
        ->callTableAction('status', $number, data: ['status' => 'suspended']);
    expect($number->fresh()->status)->toBe('suspended');

    Livewire::test(CreateCommunicationProvider::class)->fillForm([
        'name' => 'Filament Carrier',
        'driver' => 'sip',
        'status' => 'active',
    ])->call('create')->assertHasNoFormErrors();
    $provider = CommunicationProvider::query()->where('name', 'Filament Carrier')->sole();
    Livewire::test(ListCommunicationProviders::class)->assertCanSeeTableRecords([$provider]);

    Livewire::test(CreateCommunicationUsageImport::class)->fillForm([
        'provider' => 'sip',
        'rows' => 10,
        'total_amount_minor' => 500,
        'currency' => 'USD',
    ])->call('create')->assertHasNoFormErrors();
    $import = CommunicationUsageImport::query()->latest('id')->firstOrFail();
    Livewire::test(ListCommunicationUsageImports::class)->assertCanSeeTableRecords([$import]);

    Livewire::test(CreateVoipAccount::class)->fillForm([
        'customer_id' => 1,
        'platform' => 'filament-sip',
        'sip_username' => 'fil-sip-user',
        'sip_secret' => 'fil-secret',
    ])->call('create')->assertHasNoFormErrors();
    $voip = VoipAccount::query()->where('sip_username', 'fil-sip-user')->sole();
    Livewire::test(ListVoipAccounts::class)
        ->assertCanSeeTableRecords([$voip])
        ->callTableAction('provision', $voip);
    expect($voip->fresh()->status)->toBe('active');
});
