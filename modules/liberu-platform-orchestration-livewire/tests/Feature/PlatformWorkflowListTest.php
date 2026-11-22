<?php

declare(strict_types=1);

use App\Models\User;
use Liberu\Platform\PlatformOrchestration\Livewire\PlatformOrchestrationLivewireServiceProvider;
use Liberu\Platform\PlatformOrchestration\Livewire\Status;
use Liberu\Platform\PlatformOrchestration\Models\PlatformWorkflow;
use Livewire\Livewire;

it('renders tenant-scoped platform workflows, validates status filter, and exposes adapter status', function (): void {
    expect(class_exists(PlatformOrchestrationLivewireServiceProvider::class))->toBeTrue();
    expect((new Status())->render())->toContain('PlatformOrchestration');

    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    PlatformWorkflow::query()->create([
        'tenant_id' => (string) $user->currentTeam->getKey(),
        'name' => 'Draft Workflow',
        'status' => 'draft',
    ]);

    PlatformWorkflow::query()->create([
        'tenant_id' => (string) $user->currentTeam->getKey(),
        'name' => 'Active Workflow',
        'status' => 'active',
    ]);

    PlatformWorkflow::query()->create([
        'tenant_id' => (string) $otherUser->currentTeam->getKey(),
        'name' => 'Other Tenant Workflow',
        'status' => 'active',
    ]);

    Livewire::test('liberu-platform-orchestration-list')
        ->assertDontSee('Draft Workflow')
        ->assertDontSee('Active Workflow')
        ->assertDontSee('Other Tenant Workflow');

    $this->actingAs($user);

    Livewire::test('liberu-platform-orchestration-list')
        ->assertSee('Draft Workflow')
        ->assertSee('Active Workflow')
        ->assertDontSee('Other Tenant Workflow')
        ->set('status', 'active')
        ->assertSee('Active Workflow')
        ->assertDontSee('Draft Workflow')
        ->set('status', 'invalid-status')
        ->assertSet('status', '')
        ->assertSee('Draft Workflow');
});
