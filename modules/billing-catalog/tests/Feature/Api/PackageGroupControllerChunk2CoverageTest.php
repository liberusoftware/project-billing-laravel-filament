<?php

declare(strict_types=1);

use App\Models\PackageGroup;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists, shows, creates, updates, and deletes package groups for the current team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;

    PackageGroup::create([
        'team_id' => $otherTeam->id,
        'name' => 'Other Team Group',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    Sanctum::actingAs($user, ['package-groups:read', 'package-groups:write']);

    $storeResponse = $this->postJson(route('package-groups.store'), [
        'name' => 'VPS Plans',
        'description' => 'Virtual private servers',
        'sort_order' => 1,
        'is_active' => true,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'VPS Plans')
        ->assertJsonPath('data.team_id', $user->currentTeam->id);

    $groupId = (int) $storeResponse->json('data.id');

    $this->getJson(route('package-groups.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $groupId);

    $this->getJson(route('package-groups.show', $groupId))
        ->assertOk()
        ->assertJsonPath('data.id', $groupId)
        ->assertJsonPath('data.name', 'VPS Plans')
        ->assertJsonStructure(['data' => ['id', 'name', 'packages']]);

    $this->patchJson(route('package-groups.update', $groupId), [
        'name' => 'Cloud VPS Plans',
        'description' => 'Updated VPS description',
        'sort_order' => 2,
        'is_active' => true,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Cloud VPS Plans')
        ->assertJsonPath('data.description', 'Updated VPS description')
        ->assertJsonPath('data.sort_order', 2);

    $this->deleteJson(route('package-groups.destroy', $groupId))
        ->assertNoContent();

    $this->assertDatabaseMissing('package_groups', ['id' => $groupId]);
});

it('adds, reorders, and removes subscription plans in a package group', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    Sanctum::actingAs($user, ['package-groups:read', 'package-groups:write']);

    $group = PackageGroup::create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Hosting Tiers',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $plan1 = SubscriptionPlan::create([
        'name' => 'Starter',
        'code' => 'chunk2-starter',
        'price' => 9.99,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    $plan2 = SubscriptionPlan::create([
        'name' => 'Pro',
        'code' => 'chunk2-pro',
        'price' => 29.99,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    $this->postJson("/api/package-groups/{$group->id}/packages", [
        'subscription_plan_id' => $plan1->id,
    ])->assertOk()
        ->assertJsonPath('message', 'Package added to group.')
        ->assertJsonCount(1, 'data.packages');

    $this->postJson("/api/package-groups/{$group->id}/packages", [
        'subscription_plan_id' => $plan2->id,
        'sort_order' => 5,
    ])->assertOk()
        ->assertJsonPath('message', 'Package added to group.')
        ->assertJsonCount(2, 'data.packages');

    $this->postJson("/api/package-groups/{$group->id}/reorder", [
        'plan_ids' => [$plan2->id, $plan1->id],
    ])->assertOk()
        ->assertJsonPath('message', 'Packages reordered.')
        ->assertJsonPath('data.packages.0.id', $plan2->id)
        ->assertJsonPath('data.packages.1.id', $plan1->id);

    $this->deleteJson("/api/package-groups/{$group->id}/packages/{$plan1->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Package removed from group.')
        ->assertJsonCount(1, 'data.packages');

    $this->assertDatabaseMissing('package_group_items', [
        'package_group_id' => $group->id,
        'subscription_plan_id' => $plan1->id,
    ]);
});

it('blocks cross-tenant mutations on another teams package group', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;

    $foreignGroup = PackageGroup::create([
        'team_id' => $otherTeam->id,
        'name' => 'Foreign Group',
        'is_active' => true,
    ]);

    $plan = SubscriptionPlan::create([
        'name' => 'Foreign Plan',
        'code' => 'chunk2-foreign',
        'price' => 15.00,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    Sanctum::actingAs($user, ['package-groups:read', 'package-groups:write']);

    $this->getJson(route('package-groups.show', $foreignGroup))->assertNotFound();
    $this->patchJson(route('package-groups.update', $foreignGroup), ['name' => 'Hijacked'])->assertNotFound();
    $this->deleteJson(route('package-groups.destroy', $foreignGroup))->assertNotFound();
    $this->postJson("/api/package-groups/{$foreignGroup->id}/packages", ['subscription_plan_id' => $plan->id])->assertNotFound();
    $this->deleteJson("/api/package-groups/{$foreignGroup->id}/packages/{$plan->id}")->assertNotFound();
    $this->postJson("/api/package-groups/{$foreignGroup->id}/reorder", ['plan_ids' => [$plan->id]])->assertNotFound();
});
