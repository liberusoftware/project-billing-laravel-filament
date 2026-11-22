<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Platform\BusinessWorkflowReconciliation\Models\ReconciliationCase;

it('manages reconciliation cases through the tenant-scoped API with idempotency', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    $this->getJson('/api/v1/liberu/business-workflow-reconciliation')->assertUnauthorized();

    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/liberu/business-workflow-reconciliation', [
        'name' => 'Missing key',
    ])->assertStatus(422);

    $createResponse = $this->withHeader('Idempotency-Key', 'case-key-1')
        ->postJson('/api/v1/liberu/business-workflow-reconciliation', [
            'name' => 'Month End Case',
            'metadata' => ['source' => 'api'],
        ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.name', 'Month End Case')
        ->assertJsonPath('data.status', 'draft');

    $recordId = (string) $createResponse->json('data.id');

    $this->withHeader('Idempotency-Key', 'case-key-1')
        ->postJson('/api/v1/liberu/business-workflow-reconciliation', [
            'name' => 'Duplicate Should Reuse',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $recordId);

    $this->getJson('/api/v1/liberu/business-workflow-reconciliation?page[size]=10')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson("/api/v1/liberu/business-workflow-reconciliation/{$recordId}")
        ->assertOk()
        ->assertJsonPath('data.id', $recordId);

    $this->patchJson("/api/v1/liberu/business-workflow-reconciliation/{$recordId}", [
        'name' => 'Month End Case Updated',
        'status' => 'active',
        'metadata' => ['step' => 2],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Month End Case Updated')
        ->assertJsonPath('data.status', 'active');

    Sanctum::actingAs($otherUser, ['*']);

    $this->getJson("/api/v1/liberu/business-workflow-reconciliation/{$recordId}")->assertNotFound();
    $this->patchJson("/api/v1/liberu/business-workflow-reconciliation/{$recordId}", ['name' => 'Nope'])->assertNotFound();
    $this->deleteJson("/api/v1/liberu/business-workflow-reconciliation/{$recordId}")->assertNotFound();

    Sanctum::actingAs($user, ['*']);

    $this->deleteJson("/api/v1/liberu/business-workflow-reconciliation/{$recordId}")->assertNoContent();

    expect(ReconciliationCase::query()->find($recordId))->toBeNull();
});
