<?php

declare(strict_types=1);

use App\Models\User;
use Liberu\Platform\RevenueAndCareOrchestration\Livewire\RevenueAndCareOrchestrationLivewireServiceProvider;
use Liberu\Platform\RevenueAndCareOrchestration\Livewire\Status;
use Liberu\Platform\RevenueAndCareOrchestration\Models\CarePlan;
use Livewire\Livewire;

it('renders tenant-scoped care plans, validates status filter, and exposes adapter status', function (): void {
    expect(class_exists(RevenueAndCareOrchestrationLivewireServiceProvider::class))->toBeTrue();
    expect((new Status())->render())->toContain('RevenueAndCareOrchestration');

    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    CarePlan::query()->create([
        'tenant_id' => (string) $user->currentTeam->getKey(),
        'name' => 'Draft Plan',
        'status' => 'draft',
    ]);

    CarePlan::query()->create([
        'tenant_id' => (string) $user->currentTeam->getKey(),
        'name' => 'Active Plan',
        'status' => 'active',
    ]);

    CarePlan::query()->create([
        'tenant_id' => (string) $otherUser->currentTeam->getKey(),
        'name' => 'Other Tenant Plan',
        'status' => 'active',
    ]);

    Livewire::test('liberu-revenue-and-care-orchestration-list')
        ->assertDontSee('Draft Plan')
        ->assertDontSee('Active Plan')
        ->assertDontSee('Other Tenant Plan');

    $this->actingAs($user);

    Livewire::test('liberu-revenue-and-care-orchestration-list')
        ->assertSee('Draft Plan')
        ->assertSee('Active Plan')
        ->assertDontSee('Other Tenant Plan')
        ->set('status', 'active')
        ->assertSee('Active Plan')
        ->assertDontSee('Draft Plan')
        ->set('status', 'invalid-status')
        ->assertSet('status', '')
        ->assertSee('Draft Plan');
});
