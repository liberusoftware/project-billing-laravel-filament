<?php

declare(strict_types=1);

use App\Models\User;
use Liberu\Platform\ExecutiveInsights\Livewire\ExecutiveInsightsLivewireServiceProvider;
use Liberu\Platform\ExecutiveInsights\Livewire\Status;
use Liberu\Platform\ExecutiveInsights\Models\InsightSnapshot;
use Livewire\Livewire;

it('renders tenant-scoped insight snapshots, validates status filter, and exposes adapter status', function (): void {
    expect(class_exists(ExecutiveInsightsLivewireServiceProvider::class))->toBeTrue();
    expect((new Status())->render())->toContain('ExecutiveInsights');

    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    InsightSnapshot::query()->create([
        'tenant_id' => (string) $user->currentTeam->getKey(),
        'name' => 'Draft Snapshot',
        'status' => 'draft',
    ]);

    InsightSnapshot::query()->create([
        'tenant_id' => (string) $user->currentTeam->getKey(),
        'name' => 'Active Snapshot',
        'status' => 'active',
    ]);

    InsightSnapshot::query()->create([
        'tenant_id' => (string) $otherUser->currentTeam->getKey(),
        'name' => 'Other Tenant Snapshot',
        'status' => 'active',
    ]);

    Livewire::test('liberu-executive-insights-list')
        ->assertDontSee('Draft Snapshot')
        ->assertDontSee('Active Snapshot')
        ->assertDontSee('Other Tenant Snapshot');

    $this->actingAs($user);

    Livewire::test('liberu-executive-insights-list')
        ->assertSee('Draft Snapshot')
        ->assertSee('Active Snapshot')
        ->assertDontSee('Other Tenant Snapshot')
        ->set('status', 'active')
        ->assertSee('Active Snapshot')
        ->assertDontSee('Draft Snapshot')
        ->set('status', 'invalid-status')
        ->assertSet('status', '')
        ->assertSee('Draft Snapshot');
});
