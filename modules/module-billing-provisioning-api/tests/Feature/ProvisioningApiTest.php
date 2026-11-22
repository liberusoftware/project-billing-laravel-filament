<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Provisioning\Api\ProvisioningApiServiceProvider;
use Liberu\Billing\Provisioning\Models\ProvisionedService;
use Liberu\Billing\Provisioning\Models\ProvisioningOperation;

uses(RefreshDatabase::class);

it('manages provisioned services and operations through the REST API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.provisioning.read', 'billing.provisioning.write']);

    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $otherService = ProvisionedService::query()->create([
        'team_id' => $otherTeam->id,
        'provider' => 'other',
        'state' => 'pending',
        'metadata' => [],
    ]);

    // Store service
    $createResponse = $this->withHeaders(['Idempotency-Key' => 'prov-create-1'])
        ->postJson('/api/v1/billing/provisioning', [
            'provider' => 'cpanel',
            'external_id' => 'ext-api-1',
            'metadata' => ['region' => 'eu'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'provisioned-service')
        ->assertJsonPath('data.attributes.provider', 'cpanel');

    $serviceId = (int) $createResponse->json('data.id');

    // List services (scoped to current team)
    $this->getJson('/api/v1/billing/provisioning?per_page=10')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $serviceId)
        ->assertJsonPath('meta.per_page', 10);

    // Show service (own vs other team)
    $this->getJson("/api/v1/billing/provisioning/{$serviceId}")
        ->assertOk()
        ->assertJsonPath('data.attributes.external_id', 'ext-api-1');

    $this->getJson("/api/v1/billing/provisioning/{$otherService->id}")
        ->assertNotFound();

    // Queue operation
    $queueResponse = $this->withHeaders(['Idempotency-Key' => 'prov-queue-1'])
        ->postJson("/api/v1/billing/provisioning/{$serviceId}/operations", [
            'operation' => 'provision',
            'payload' => ['disk' => 50],
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.type', 'provisioning-operation')
        ->assertJsonPath('data.attributes.operation', 'provision');

    $operationId = (int) $queueResponse->json('data.id');
    expect(ProvisioningOperation::query()->find($operationId))->not->toBeNull();

    // List operations
    $this->getJson('/api/v1/billing/provisioning/operations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $operationId);

    // Reconcile service
    $this->withHeaders(['Idempotency-Key' => 'prov-reconcile-1'])
        ->postJson("/api/v1/billing/provisioning/{$serviceId}/reconcile")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $serviceId);

    expect(ProvisionedService::query()->find($serviceId)->last_reconciled_at)->not->toBeNull();
});

it('rejects API requests when the authenticated user has no current team', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['billing.provisioning.read', 'billing.provisioning.write']);

    $this->getJson('/api/v1/billing/provisioning')->assertForbidden();
});

it('boots the provisioning API service provider', function (): void {
    $provider = new ProvisioningApiServiceProvider(app());
    $provider->boot();

    expect(true)->toBeTrue();
});
