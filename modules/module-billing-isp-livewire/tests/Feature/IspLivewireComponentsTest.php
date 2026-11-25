<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Isp\Contracts\NetworkAdapter;
use Liberu\Billing\Isp\Livewire\Components\AccessServices;
use Liberu\Billing\Isp\Livewire\Components\IspCapabilities;
use Liberu\Billing\Isp\Models\AccessService;
use Liberu\Billing\Isp\Models\IspCapability;
use Liberu\Billing\Isp\Services\NetworkAdapterRegistry;
use Livewire\Livewire;

it('manages ISP access services and capabilities through Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.isp.read', 'billing.isp.write']);

    app(NetworkAdapterRegistry::class)->register(new class() implements NetworkAdapter
    {
        public function key(): string
        {
            return 'lw-isp-adapter';
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

    $servicesComponent = Livewire::test(AccessServices::class)
        ->set('name', 'Home Fiber')
        ->set('monthlyDataLimitBytes', 50000)
        ->call('createService')
        ->assertHasNoErrors();

    $service = AccessService::query()->where('name', 'Home Fiber')->sole();

    $servicesComponent
        ->set('selectedServiceId', $service->id)
        ->set('status', 'active')
        ->call('transitionService')
        ->assertHasNoErrors()
        ->set('adapter', 'lw-isp-adapter')
        ->call('synchronize')
        ->assertHasNoErrors()
        ->set('accountingSessionId', 'lw-sess-1')
        ->set('accountingStartedAt', now()->toIso8601String())
        ->set('inputBytes', 100)
        ->set('outputBytes', 200)
        ->call('recordAccounting')
        ->assertHasNoErrors()
        ->call('resetUsage')
        ->assertHasNoErrors();

    expect($service->fresh()->current_period_usage_bytes)->toBe(0);

    $capComponent = Livewire::test(IspCapabilities::class)
        ->set('type', 'radius')
        ->set('name', 'Core NAS')
        ->call('createCapability')
        ->assertHasNoErrors();

    $capability = IspCapability::query()->where('name', 'Core NAS')->sole();

    $capComponent
        ->set('selectedCapabilityId', $capability->id)
        ->set('status', 'active')
        ->call('transitionCapability')
        ->assertHasNoErrors();

    expect($capability->fresh()->status)->toBe('active');
});
