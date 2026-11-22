<?php

declare(strict_types=1);

use App\Models\User;
use Liberu\Platform\BusinessWorkflowReconciliation\Livewire\BusinessWorkflowReconciliationLivewireServiceProvider;
use Liberu\Platform\BusinessWorkflowReconciliation\Livewire\Status;
use Liberu\Platform\BusinessWorkflowReconciliation\Models\ReconciliationCase;
use Livewire\Livewire;

it('renders tenant-scoped reconciliation cases, validates status filter, and exposes adapter status', function (): void {
    expect(class_exists(BusinessWorkflowReconciliationLivewireServiceProvider::class))->toBeTrue();
    expect((new Status())->render())->toContain('BusinessWorkflowReconciliation');

    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    ReconciliationCase::query()->create([
        'tenant_id' => (string) $user->currentTeam->getKey(),
        'name' => 'Draft Case',
        'status' => 'draft',
    ]);

    ReconciliationCase::query()->create([
        'tenant_id' => (string) $user->currentTeam->getKey(),
        'name' => 'Active Case',
        'status' => 'active',
    ]);

    ReconciliationCase::query()->create([
        'tenant_id' => (string) $otherUser->currentTeam->getKey(),
        'name' => 'Other Tenant Case',
        'status' => 'active',
    ]);

    Livewire::test('liberu-business-workflow-reconciliation-list')
        ->assertDontSee('Draft Case')
        ->assertDontSee('Active Case')
        ->assertDontSee('Other Tenant Case');

    $this->actingAs($user);

    Livewire::test('liberu-business-workflow-reconciliation-list')
        ->assertSee('Draft Case')
        ->assertSee('Active Case')
        ->assertDontSee('Other Tenant Case')
        ->set('status', 'active')
        ->assertSee('Active Case')
        ->assertDontSee('Draft Case')
        ->set('status', 'invalid-status')
        ->assertSet('status', '')
        ->assertSee('Draft Case');
});
