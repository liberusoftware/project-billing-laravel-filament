<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Isp\Contracts\NetworkAdapter;
use Liberu\Billing\Isp\Services\NetworkAdapterRegistry;

it('runs full ISP access service and capability lifecycle through the API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.isp.read', 'billing.isp.write']);

    app(NetworkAdapterRegistry::class)->register(new class() implements NetworkAdapter
    {
        public function key(): string
        {
            return 'test-isp-adapter';
        }

        public function install(array $attributes): array
        {
            return ['installed' => true];
        }

        public function suspend(array $attributes): array
        {
            return ['suspended' => true];
        }

        public function remove(array $attributes): array
        {
            return ['removed' => true];
        }
    });

    $serviceId = $this->postJson(route('billing.isp.store'), [
        'name' => 'Fiber 1Gbps',
        'monthly_data_limit_bytes' => 10000,
    ])->assertCreated()->assertJsonPath('data.type', 'access-service')->json('data.id');

    $this->getJson(route('billing.isp.services.index', ['per_page' => 5]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $serviceId);

    $this->getJson(route('billing.isp.services.show', $serviceId))
        ->assertOk()
        ->assertJsonPath('data.id', $serviceId);

    $this->patchJson(route('billing.isp.services.lifecycle', $serviceId), ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');

    $this->postJson(route('billing.isp.services.synchronize', $serviceId), ['adapter' => 'test-isp-adapter'])
        ->assertOk()
        ->assertJsonPath('data.id', $serviceId);

    $this->postJson(route('billing.isp.services.accounting', $serviceId), [
        'accounting_session_id' => 'sess-001',
        'started_at' => now()->toIso8601String(),
        'input_bytes' => 1000,
        'output_bytes' => 2000,
    ])->assertCreated()->assertJsonPath('data.type', 'radius-session');

    $this->postJson(route('billing.isp.services.usage.reset', $serviceId))
        ->assertOk()
        ->assertJsonPath('data.attributes.current_period_usage_bytes', 0);

    $capabilityId = $this->postJson(route('billing.isp.capabilities.store'), [
        'type' => 'coverage',
        'name' => 'Metro Zone',
        'identifier' => 'metro-1',
    ])->assertCreated()->assertJsonPath('data.type', 'isp-capability')->json('data.id');

    $this->getJson(route('billing.isp.capabilities.index', ['type' => 'coverage']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $capabilityId);

    $this->patchJson(route('billing.isp.capabilities.lifecycle', $capabilityId), ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');
});
