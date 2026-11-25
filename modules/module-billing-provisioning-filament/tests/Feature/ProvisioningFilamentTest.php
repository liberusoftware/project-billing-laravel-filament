<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Provisioning\Contracts\ProvisioningDriver;
use Liberu\Billing\Provisioning\Enums\ProvisioningState;
use Liberu\Billing\Provisioning\Filament\ProvisioningFilamentPlugin;
use Liberu\Billing\Provisioning\Filament\Resources\ProvisionedServiceResource;
use Liberu\Billing\Provisioning\Filament\Resources\ProvisionedServiceResource\Pages\CreateProvisionedService;
use Liberu\Billing\Provisioning\Filament\Resources\ProvisionedServiceResource\Pages\ListProvisionedServices;
use Liberu\Billing\Provisioning\Filament\Resources\ProvisioningOperationResource;
use Liberu\Billing\Provisioning\Models\ProvisionedService;
use Liberu\Billing\Provisioning\Models\ProvisioningOperation;
use Liberu\Billing\Provisioning\Services\ProvisioningDriverRegistry;
use Livewire\Livewire;

uses(RefreshDatabase::class);

final class TestListProvisioningOperations extends ListRecords
{
    protected static string $resource = ProvisioningOperationResource::class;
}

it('creates, lists, and reconciles provisioned services through Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.provisioning.read', 'billing.provisioning.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    // Exercise plugin methods explicitly
    $plugin = ProvisioningFilamentPlugin::make();
    expect($plugin->getId())->toBe('module-billing-provisioning-filament');
    $plugin->register($panel);
    $plugin->boot($panel);

    Livewire::test(CreateProvisionedService::class)
        ->fillForm([
            'provider' => 'filament-provider',
            'state' => 'pending',
            'external_id' => 'fil-ext-1',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $service = ProvisionedService::query()->where('provider', 'filament-provider')->sole();
    expect($service->team_id)->toBe($actor->current_team_id);

    $foreign = ProvisionedService::query()->create([
        'team_id' => $actor->current_team_id + 1,
        'provider' => 'foreign-provider',
        'state' => ProvisioningState::Pending,
        'metadata' => [],
    ]);

    Livewire::test(ListProvisionedServices::class)
        ->assertCanSeeTableRecords([$service])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('reconcile', $service);

    expect($service->fresh()->last_reconciled_at)->not->toBeNull();
    expect(ProvisionedServiceResource::getPages())->toHaveKeys(['index', 'create']);
});

it('lists and runs provisioning operations through the Filament operation resource', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.provisioning.read', 'billing.provisioning.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    app(ProvisioningDriverRegistry::class)->register('filament-op-driver', new class() implements ProvisioningDriver
    {
        public function provision(ProvisionedService $service): string
        {
            return 'fil-op-ext-1';
        }

        public function deprovision(ProvisionedService $service): void {}

        public function poll(ProvisionedService $service): array
        {
            return ['state' => ProvisioningState::Active->value];
        }
    });

    $service = ProvisionedService::query()->create([
        'team_id' => $actor->current_team_id,
        'provider' => 'filament-op-driver',
        'state' => ProvisioningState::Pending,
        'metadata' => [],
    ]);
    $queued = ProvisioningOperation::query()->create([
        'team_id' => $actor->current_team_id,
        'provisioned_service_id' => $service->id,
        'operation' => 'provision',
        'status' => 'queued',
        'payload' => [],
    ]);
    $completed = ProvisioningOperation::query()->create([
        'team_id' => $actor->current_team_id,
        'provisioned_service_id' => $service->id,
        'operation' => 'poll',
        'status' => 'completed',
        'payload' => [],
    ]);
    $foreign = ProvisioningOperation::query()->create([
        'team_id' => $actor->current_team_id + 1,
        'provisioned_service_id' => $service->id,
        'operation' => 'provision',
        'status' => 'queued',
        'payload' => [],
    ]);

    Livewire::test(TestListProvisioningOperations::class)
        ->assertCanSeeTableRecords([$queued, $completed])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableActionVisible('run', $queued)
        ->assertTableActionHidden('run', $completed)
        ->callTableAction('run', $queued);

    expect($queued->fresh()->status)->toBe('completed')
        ->and($service->fresh()->external_id)->toBe('fil-op-ext-1');
});
