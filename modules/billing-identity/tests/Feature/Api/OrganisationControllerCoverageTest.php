<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists child organisations, updates a child, lists its brands, and archives it via OrganisationController', function (): void {
    $user = User::factory()->create();
    $provider = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $provider->id])->save();

    $child = Team::query()->create([
        'parent_team_id' => $provider->id,
        'user_id' => $user->id,
        'name' => 'Child Reseller Org',
        'organisation_type' => OrganisationType::Reseller,
        'slug' => 'child-reseller-org',
        'database_mode' => 'shared',
        'personal_team' => false,
    ]);

    $brand = $child->brands()->create([
        'name' => 'Child Brand',
        'slug' => 'child-brand',
        'is_primary' => true,
    ]);
    $brand->domains()->create([
        'domain' => 'child.brand.example',
        'is_primary' => true,
    ]);

    Sanctum::actingAs($user, ['organisations:read', 'organisations:write']);

    // children()
    $this->getJson('/api/organisations')
        ->assertOk()
        ->assertJsonPath('data.0.id', $child->id)
        ->assertJsonPath('data.0.brands_count', 1);

    // updateChild()
    $this->patchJson("/api/organisations/{$child->id}", [
        'name' => 'Updated Child Reseller',
        'organisation_type' => OrganisationType::WhiteLabel->value,
        'slug' => 'updated-child-reseller',
        'custom_domain' => 'updated-child.example.com',
        'database_mode' => 'isolated',
        'branding' => ['primary' => '#223344'],
    ])
        ->assertOk()
        ->assertJsonPath('name', 'Updated Child Reseller')
        ->assertJsonPath('slug', 'updated-child-reseller')
        ->assertJsonPath('database_mode', 'isolated');

    // brands()
    $this->getJson("/api/organisations/{$child->id}/brands")
        ->assertOk()
        ->assertJsonPath('0.id', $brand->id)
        ->assertJsonPath('0.domains.0.domain', 'child.brand.example');

    // archive()
    $this->postJson("/api/organisations/{$child->id}/archive")
        ->assertOk();

    expect($child->fresh()->archived_at)->not->toBeNull();
});
