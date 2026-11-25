<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Provisioning\Contracts\ProvisioningDriver;
use Liberu\Billing\Provisioning\Enums\ProvisioningState;
use Liberu\Billing\Provisioning\Livewire\Components\ProvisioningOperations;
use Liberu\Billing\Provisioning\Livewire\ProvisioningLivewireServiceProvider;
use Liberu\Billing\Provisioning\Models\ProvisionedService;
use Liberu\Billing\Provisioning\Models\ProvisioningOperation;
use Liberu\Billing\Provisioning\Services\ProvisioningDriverRegistry;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates, transitions, queues, reconciles, and runs provisioning operations via Livewire', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.provisioning.read', 'billing.provisioning.write']);

    app(ProvisioningDriverRegistry::class)->register('lw-driver', new class() implements ProvisioningDriver
    {
        public function provision(ProvisionedService $service): string
        {
            return 'lw-ext-1';
        }

        public function deprovision(ProvisionedService $service): void {}

        public function poll(ProvisionedService $service): array
        {
            return ['state' => ProvisioningState::Active->value];
        }
    });

    $component = Livewire::test(ProvisioningOperations::class)
        ->assertSee('No provisioning operations found.')
        ->set('provider', 'lw-driver')
        ->set('externalId', 'initial-ext')
        ->call('createService')
        ->assertHasNoErrors()
        ->assertSet('provider', '')
        ->assertSee('Provisioned service created.');

    $service = ProvisionedService::query()->where('provider', 'lw-driver')->sole();
    expect($service->team_id)->toBe($user->current_team_id);

    $component
        ->set('selectedServiceId', $service->id)
        ->set('state', 'provisioning')
        ->set('lastError', 'starting')
        ->call('transitionService')
        ->assertHasNoErrors()
        ->assertSee('Provisioned service state updated.');

    expect($service->fresh()->state)->toBe(ProvisioningState::Provisioning);

    $component
        ->set('selectedServiceId', $service->id)
        ->call('reconcile')
        ->assertHasNoErrors()
        ->assertSee('Provisioned service reconciled.');

    expect($service->fresh()->last_reconciled_at)->not->toBeNull();

    $component
        ->set('selectedServiceId', $service->id)
        ->set('operation', 'provision')
        ->call('queue')
        ->assertHasNoErrors()
        ->assertSee('Provisioning operation queued.');

    $operation = ProvisioningOperation::query()->where('provisioned_service_id', $service->id)->sole();

    $component
        ->set('selectedOperationId', $operation->id)
        ->call('run')
        ->assertHasNoErrors()
        ->assertSet('selectedOperationId', null)
        ->assertSee('Provisioning operation run.');

    expect($operation->fresh()->status)->toBe('completed')
        ->and($service->fresh()->external_id)->toBe('lw-ext-1');
});

it('rejects rendering the Livewire component when the user has no current team', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['billing.provisioning.read', 'billing.provisioning.write']);

    Livewire::test(ProvisioningOperations::class)->assertForbidden();
});

it('boots the provisioning Livewire service provider', function (): void {
    $provider = new ProvisioningLivewireServiceProvider(app());
    $provider->boot();

    expect(true)->toBeTrue();
});
