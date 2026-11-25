<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\InfrastructureController;
use App\Models\Customer;
use App\Models\InfrastructureAsset;
use App\Models\IspService;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Models\VoipAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InfrastructureApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_asset_pool_allocate_and_release_address(): void
    {
        [, $team] = $this->operator();

        $asset = $this->postJson('/api/infrastructure/assets', [
            'asset_type' => 'router',
            'name' => 'Core Router',
            'hostname' => 'core-1.example.test',
            'serial_number' => 'SERIAL-1',
        ])->assertCreated()->json();

        $pool = $this->postJson('/api/infrastructure/ip-pools', [
            'name' => 'Customer IPv4',
            'cidr' => '192.0.2.0/30',
            'gateway' => '192.0.2.1',
            'infrastructure_asset_id' => $asset['id'],
            'vlan_id' => 100,
        ])->assertCreated()
            ->assertJsonPath('team_id', $team->id)
            ->assertJsonPath('first_address', '192.0.2.1')
            ->json();

        $address = $this->postJson("/api/infrastructure/ip-pools/{$pool['id']}/allocate", [
            'target_type' => 'asset',
            'target_id' => $asset['id'],
            'hostname' => 'core-1.example.test',
        ])->assertCreated()
            ->assertJsonPath('address', '192.0.2.1')
            ->json();

        $this->postJson("/api/infrastructure/ip-addresses/{$address['id']}/release")
            ->assertOk()
            ->assertJsonPath('status', 'available')
            ->assertJsonPath('assignable_id', null);
    }

    public function test_tenant_cannot_use_another_tenants_assets_or_pools(): void
    {
        $this->operator();
        $otherAsset = InfrastructureAsset::factory()->create();

        $this->patchJson("/api/infrastructure/assets/{$otherAsset->id}", ['name' => 'Stolen'])
            ->assertNotFound();
        $this->postJson('/api/infrastructure/ip-pools', [
            'name' => 'Foreign',
            'cidr' => '10.10.0.0/24',
            'infrastructure_asset_id' => $otherAsset->id,
        ])->assertUnprocessable();
    }

    public function test_tenant_can_list_update_assets_pools_addresses_and_allocate_all_target_types(): void
    {
        [, $team] = $this->operator();

        $parent = InfrastructureAsset::factory()->create(['team_id' => $team->id]);
        $asset = InfrastructureAsset::factory()->create(['team_id' => $team->id, 'parent_id' => $parent->id]);

        $this->getJson('/api/infrastructure/assets')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->patchJson("/api/infrastructure/assets/{$asset->id}", [
            'name' => 'Renamed Router',
            'status' => 'maintenance',
        ])->assertOk()
            ->assertJsonPath('name', 'Renamed Router')
            ->assertJsonPath('status', 'maintenance');

        $pool = $this->postJson('/api/infrastructure/ip-pools', [
            'name' => 'Multi Target Pool',
            'cidr' => '10.20.0.0/28',
            'infrastructure_asset_id' => $asset->id,
        ])->assertCreated()->json();

        $this->getJson('/api/infrastructure/ip-pools')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $subscription = Subscription::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
        ]);
        $ispService = IspService::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
        ]);
        $voipAccount = VoipAccount::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
        ]);

        foreach ([
            ['customer', $customer->id],
            ['subscription', $subscription->id],
            ['isp_service', $ispService->id],
            ['voip_account', $voipAccount->id],
        ] as [$type, $id]) {
            $this->postJson("/api/infrastructure/ip-pools/{$pool['id']}/allocate", [
                'target_type' => $type,
                'target_id' => $id,
            ])->assertCreated();
        }

        $this->getJson("/api/infrastructure/ip-pools/{$pool['id']}/addresses")
            ->assertOk()
            ->assertJsonCount(4, 'data');

        // Exercise default match arm in assignmentTarget
        $controller = app(InfrastructureController::class);
        $method = new \ReflectionMethod($controller, 'assignmentTarget');
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($controller, request(), 'unsupported_type', 1);
    }

    /** @return array{User, Team} */
    private function operator(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->update(['current_team_id' => $team->id]);
        Sanctum::actingAs($user, ['infrastructure:read', 'infrastructure:write']);

        return [$user, $team];
    }
}
