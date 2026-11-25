<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Isp\Actions\CreateAccessService as CreateAccessServiceAction;
use Liberu\Billing\Isp\Contracts\NetworkAdapter;
use Liberu\Billing\Isp\Filament\Resources\AccessServiceResource\Pages\CreateAccessService;
use Liberu\Billing\Isp\Filament\Resources\AccessServiceResource\Pages\ListAccessServices;
use Liberu\Billing\Isp\Filament\Resources\IspCapabilityResource\Pages\CreateIspCapability;
use Liberu\Billing\Isp\Filament\Resources\IspCapabilityResource\Pages\ListIspCapabilities;
use Liberu\Billing\Isp\Models\AccessService;
use Liberu\Billing\Isp\Models\IspCapability;
use Liberu\Billing\Isp\Services\NetworkAdapterRegistry;
use Livewire\Livewire;

it('manages ISP access services and capabilities through Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.isp.read', 'billing.isp.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    app(NetworkAdapterRegistry::class)->register(new class() implements NetworkAdapter
    {
        public function key(): string
        {
            return 'filament-isp-adapter';
        }

        public function install(array $attributes): array
        {
            return ['ok' => true];
        }

        public function suspend(array $attributes): array
        {
            return ['ok' => true];
        }

        public function remove(array $attributes): array
        {
            return ['ok' => true];
        }
    });

    Livewire::test(CreateAccessService::class)->fillForm([
        'name' => 'Business Fiber',
        'status' => 'active',
        'monthly_data_limit_bytes' => 50000,
    ])->call('create')->assertHasNoFormErrors();

    $service = AccessService::query()->where('name', 'Business Fiber')->sole();
    $foreign = app(CreateAccessServiceAction::class)->handle($actor->current_team_id + 1, ['name' => 'Foreign Fiber']);

    Livewire::test(ListAccessServices::class)
        ->assertCanSeeTableRecords([$service])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('transition', $service, data: ['status' => 'suspended'])
        ->callTableAction('synchronize', $service, data: ['adapter' => 'filament-isp-adapter'])
        ->callTableAction('recordAccounting', $service, data: [
            'accounting_session_id' => 'fil-sess-1',
            'started_at' => now()->format('Y-m-d\TH:i'),
            'input_bytes' => 500,
            'output_bytes' => 500,
        ])
        ->callTableAction('resetUsage', $service);

    expect($service->fresh()->current_period_usage_bytes)->toBe(0);

    Livewire::test(CreateIspCapability::class)->fillForm([
        'type' => 'coverage',
        'name' => 'North Zone',
        'identifier' => 'north-1',
    ])->call('create')->assertHasNoFormErrors();

    $capability = IspCapability::query()->where('name', 'North Zone')->sole();

    Livewire::test(ListIspCapabilities::class)
        ->assertCanSeeTableRecords([$capability])
        ->callTableAction('lifecycle', $capability, data: ['status' => 'active']);

    expect($capability->fresh()->status)->toBe('active');
});
