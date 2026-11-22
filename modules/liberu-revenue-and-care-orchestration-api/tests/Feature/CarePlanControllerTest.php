<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Platform\RevenueAndCareOrchestration\Models\CarePlan;

it('manages care plans through the tenant-scoped API with idempotency', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    $this->getJson('/api/v1/liberu/revenue-and-care-orchestration')->assertUnauthorized();

    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/liberu/revenue-and-care-orchestration', [
        'name' => 'Missing key',
    ])->assertStatus(422);

    $createResponse = $this->withHeader('Idempotency-Key', 'care-plan-key-1')
        ->postJson('/api/v1/liberu/revenue-and-care-orchestration', [
            'name' => 'Enterprise Care Plan',
            'metadata' => ['source' => 'api'],
        ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.name', 'Enterprise Care Plan')
        ->assertJsonPath('data.status', 'draft');

    $recordId = (string) $createResponse->json('data.id');

    $this->withHeader('Idempotency-Key', 'care-plan-key-1')
        ->postJson('/api/v1/liberu/revenue-and-care-orchestration', [
            'name' => 'Duplicate Should Reuse',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $recordId);

    $this->getJson('/api/v1/liberu/revenue-and-care-orchestration?page[size]=10')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->getJson("/api/v1/liberu/revenue-and-care-orchestration/{$recordId}")
        ->assertOk()
        ->assertJsonPath('data.id', $recordId);

    $this->patchJson("/api/v1/liberu/revenue-and-care-orchestration/{$recordId}", [
        'name' => 'Enterprise Care Plan Updated',
        'status' => 'active',
        'metadata' => ['step' => 2],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Enterprise Care Plan Updated')
        ->assertJsonPath('data.status', 'active');

    Sanctum::actingAs($otherUser, ['*']);

    $this->getJson("/api/v1/liberu/revenue-and-care-orchestration/{$recordId}")->assertNotFound();
    $this->patchJson("/api/v1/liberu/revenue-and-care-orchestration/{$recordId}", ['name' => 'Nope'])->assertNotFound();
    $this->deleteJson("/api/v1/liberu/revenue-and-care-orchestration/{$recordId}")->assertNotFound();

    Sanctum::actingAs($user, ['*']);

    $this->deleteJson("/api/v1/liberu/revenue-and-care-orchestration/{$recordId}")->assertNoContent();

    expect(CarePlan::query()->find($recordId))->toBeNull();
});
