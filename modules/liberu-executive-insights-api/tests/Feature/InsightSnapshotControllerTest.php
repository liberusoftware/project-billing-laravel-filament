<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Platform\ExecutiveInsights\Models\InsightSnapshot;

it('manages insight snapshots through the tenant-scoped API with idempotency', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    $this->getJson('/api/v1/liberu/executive-insights')->assertUnauthorized();

    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/liberu/executive-insights', [
        'name' => 'Missing key',
    ])->assertStatus(422);

    $createResponse = $this->withHeader('Idempotency-Key', 'insight-key-1')
        ->postJson('/api/v1/liberu/executive-insights', [
            'name' => 'Q4 ARR Snapshot',
            'metadata' => ['source' => 'api'],
        ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.name', 'Q4 ARR Snapshot')
        ->assertJsonPath('data.status', 'draft');

    $recordId = (string) $createResponse->json('data.id');

    $this->withHeader('Idempotency-Key', 'insight-key-1')
        ->postJson('/api/v1/liberu/executive-insights', [
            'name' => 'Duplicate Should Reuse',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $recordId);

    $this->getJson('/api/v1/liberu/executive-insights?page[size]=10')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson("/api/v1/liberu/executive-insights/{$recordId}")
        ->assertOk()
        ->assertJsonPath('data.id', $recordId);

    $this->patchJson("/api/v1/liberu/executive-insights/{$recordId}", [
        'name' => 'Q4 ARR Snapshot Updated',
        'status' => 'active',
        'metadata' => ['step' => 2],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Q4 ARR Snapshot Updated')
        ->assertJsonPath('data.status', 'active');

    Sanctum::actingAs($otherUser, ['*']);

    $this->getJson("/api/v1/liberu/executive-insights/{$recordId}")->assertNotFound();
    $this->patchJson("/api/v1/liberu/executive-insights/{$recordId}", ['name' => 'Nope'])->assertNotFound();
    $this->deleteJson("/api/v1/liberu/executive-insights/{$recordId}")->assertNotFound();

    Sanctum::actingAs($user, ['*']);

    $this->deleteJson("/api/v1/liberu/executive-insights/{$recordId}")->assertNoContent();

    expect(InsightSnapshot::query()->find($recordId))->toBeNull();
});
