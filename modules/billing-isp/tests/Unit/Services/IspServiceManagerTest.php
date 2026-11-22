<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\RadiusClient;
use App\Enums\IspServiceStatus;
use App\Models\IspService;
use App\Services\IspServiceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IspServiceManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_synchronizes_radius_and_activates_subscriber(): void
    {
        $service = IspService::factory()->create();
        $radius = new FakeRadiusClient();

        $activated = app(IspServiceManager::class)->activate($service, $radius);

        $this->assertSame(IspServiceStatus::Active, $activated->status);
        $this->assertNotNull($activated->activated_at);
        $this->assertNotNull($activated->radius_synced_at);
        $this->assertSame([$service->id], $radius->synchronized);
    }

    public function test_accounting_updates_are_idempotent_and_track_bandwidth_usage(): void
    {
        $service = IspService::factory()->create(['status' => IspServiceStatus::Active]);
        $radius = new FakeRadiusClient();
        $manager = app(IspServiceManager::class);

        $payload = [
            'accounting_session_id' => 'session-1',
            'started_at' => now()->subMinute()->toISOString(),
            'input_bytes' => 100,
            'output_bytes' => 900,
            'session_seconds' => 60,
        ];

        $manager->recordAccounting($service, $payload, $radius);
        $manager->recordAccounting($service, $payload, $radius);

        $this->assertSame(1000, $service->refresh()->current_period_usage_bytes);
        $this->assertDatabaseCount('radius_sessions', 1);

        $payload['output_bytes'] = 1400;
        $manager->recordAccounting($service, $payload, $radius);

        $this->assertSame(1500, $service->refresh()->current_period_usage_bytes);
    }

    public function test_data_allowance_enforcement_suspends_and_disconnects_subscriber(): void
    {
        $service = IspService::factory()->create([
            'status' => IspServiceStatus::Active,
            'monthly_data_limit_bytes' => 1000,
        ]);
        $radius = new FakeRadiusClient();

        app(IspServiceManager::class)->recordAccounting($service, [
            'accounting_session_id' => 'over-limit',
            'started_at' => now()->subMinute()->toISOString(),
            'input_bytes' => 400,
            'output_bytes' => 700,
        ], $radius);

        $service->refresh();
        $this->assertSame(IspServiceStatus::Suspended, $service->status);
        $this->assertSame('Monthly data allowance exceeded.', $service->suspension_reason);
        $this->assertSame([$service->id], $radius->suspended);
        $this->assertSame([$service->id], $radius->disconnected);
    }

    public function test_radius_secret_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $service = IspService::factory()->create(['radius_secret' => 'highly-secret-value']);

        $rawSecret = $service->getRawOriginal('radius_secret');

        $this->assertNotSame('highly-secret-value', $rawSecret);
        $this->assertSame('highly-secret-value', $service->radius_secret);
        $this->assertArrayNotHasKey('radius_secret', $service->toArray());
    }

    public function test_synchronize_reset_usage_terminated_guards_and_model_relationships(): void
    {
        $team = \App\Models\Team::factory()->create();
        $parentAsset = \App\Models\InfrastructureAsset::factory()->create(['team_id' => $team->id]);
        $childAsset = \App\Models\InfrastructureAsset::factory()->create([
            'team_id' => $team->id,
            'parent_id' => $parentAsset->id,
        ]);
        $this->assertTrue($childAsset->parent->is($parentAsset));
        $this->assertCount(1, $parentAsset->children);
        $this->assertCount(0, $parentAsset->ipPools);

        $subscription = \App\Models\Subscription::factory()->create(['team_id' => $team->id]);
        $service = IspService::factory()->create([
            'team_id' => $team->id,
            'subscription_id' => $subscription->id,
            'current_period_usage_bytes' => 5000,
        ]);
        $this->assertTrue($service->subscription->is($subscription));

        $session = \App\Models\RadiusSession::factory()->create(['isp_service_id' => $service->id]);
        $this->assertTrue($session->ispService->is($service));

        $radius = new FakeRadiusClient();
        $manager = app(IspServiceManager::class);

        $synced = $manager->synchronize($service, $radius);
        $this->assertNotNull($synced->radius_synced_at);
        $this->assertSame([$service->id], $radius->synchronized);

        $reset = $manager->resetUsagePeriod($service);
        $this->assertSame(0, $reset->current_period_usage_bytes);

        $terminated = IspService::factory()->create(['status' => IspServiceStatus::Terminated]);
        try {
            $manager->activate($terminated, $radius);
            $this->fail('Expected InvalidArgumentException on activate of terminated service.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('terminated', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $manager->suspend($terminated, $radius, 'abuse');
    }
}

final class FakeRadiusClient implements RadiusClient
{
    /** @var list<int> */
    public array $synchronized = [];

    /** @var list<int> */
    public array $suspended = [];

    /** @var list<int> */
    public array $disconnected = [];

    public function synchronizeUser(IspService $service): void
    {
        $this->synchronized[] = $service->id;
    }

    public function suspendUser(IspService $service): void
    {
        $this->suspended[] = $service->id;
    }

    public function disconnectUser(IspService $service): void
    {
        $this->disconnected[] = $service->id;
    }
}
