<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Team;
use App\Models\User;
use App\Services\TeamManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementServiceChunk21CoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_user_to_default_team_attaches_user_as_admin_and_sets_current_team(): void
    {
        $owner = User::factory()->create();
        $defaultTeam = Team::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Default Org',
            'personal_team' => false,
            'is_default_for_registration' => true,
        ]);

        $user = User::factory()->create(['current_team_id' => null]);

        app(TeamManagementService::class)->assignUserToDefaultTeam($user);

        $user->refresh();

        $this->assertTrue($user->belongsToTeam($defaultTeam));
        $this->assertSame($defaultTeam->id, $user->current_team_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $defaultTeam->id,
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
    }

    public function test_assign_user_to_default_team_switches_current_team_without_reattaching_existing_member(): void
    {
        $owner = User::factory()->create();
        $defaultTeam = Team::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Default Org',
            'personal_team' => false,
            'is_default_for_registration' => true,
        ]);

        $user = User::factory()->create(['current_team_id' => null]);
        $user->teams()->attach($defaultTeam, ['role' => 'editor']);

        app(TeamManagementService::class)->assignUserToDefaultTeam($user);

        $user->refresh();

        $this->assertSame($defaultTeam->id, $user->current_team_id);
        $this->assertSame(1, $user->teams()->where('teams.id', $defaultTeam->id)->count());
    }
}
