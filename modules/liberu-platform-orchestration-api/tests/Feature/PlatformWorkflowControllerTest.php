<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Platform\PlatformOrchestration\Models\PlatformWorkflow;

it('manages platform workflows through the tenant-scoped API with idempotency', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    $this->getJson('/api/v1/liberu/platform-orchestration')->assertUnauthorized();

    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/liberu/platform-orchestration', [
        'name' => 'Missing key',
    ])->assertStatus(422);

    $createResponse = $this->withHeader('Idempotency-Key', 'workflow-key-1')
        ->postJson('/api/v1/liberu/platform-orchestration', [
            'name' => 'Provisioning Flow',
            'metadata' => ['source' => 'api'],
        ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.name', 'Provisioning Flow')
        ->assertJsonPath('data.status', 'draft');

    $recordId = (string) $createResponse->json('data.id');

    $this->withHeader('Idempotency-Key', 'workflow-key-1')
        ->postJson('/api/v1/liberu/platform-orchestration', [
            'name' => 'Duplicate Should Reuse',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $recordId);

    $this->getJson('/api/v1/liberu/platform-orchestration?page[size]=10')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson("/api/v1/liberu/platform-orchestration/{$recordId}")
        ->assertOk()
        ->assertJsonPath('data.id', $recordId);

    $this->patchJson("/api/v1/liberu/platform-orchestration/{$recordId}", [
        'name' => 'Provisioning Flow Updated',
        'status' => 'active',
        'metadata' => ['step' => 2],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Provisioning Flow Updated')
        ->assertJsonPath('data.status', 'active');

    Sanctum::actingAs($otherUser, ['*']);

    $this->getJson("/api/v1/liberu/platform-orchestration/{$recordId}")->assertNotFound();
    $this->patchJson("/api/v1/liberu/platform-orchestration/{$recordId}", ['name' => 'Nope'])->assertNotFound();
    $this->deleteJson("/api/v1/liberu/platform-orchestration/{$recordId}")->assertNotFound();

    Sanctum::actingAs($user, ['*']);

    $this->deleteJson("/api/v1/liberu/platform-orchestration/{$recordId}")->assertNoContent();

    expect(PlatformWorkflow::query()->find($recordId))->toBeNull();
});
