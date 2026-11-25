<?php

declare(strict_types=1);

use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUserWithTeams;
use App\Models\Team;
use App\Models\User;

it('deletes a user along with owned teams, team memberships, and api tokens', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $ownedPersonalTeam = $user->ownedTeams()->firstOrFail();

    $secondOwnedTeam = Team::factory()->create([
        'user_id' => $user->id,
        'personal_team' => false,
    ]);

    $otherOwner = User::factory()->withPersonalTeam()->create();
    $memberTeam = Team::factory()->create([
        'user_id' => $otherOwner->id,
        'personal_team' => false,
    ]);
    $memberTeam->users()->attach($user, ['role' => 'editor']);

    $user->createToken('ci-token');

    $action = new DeleteUserWithTeams(new DeleteTeam());
    $action->delete($user);

    expect($user->fresh())->toBeNull()
        ->and($ownedPersonalTeam->fresh())->toBeNull()
        ->and($secondOwnedTeam->fresh())->toBeNull()
        ->and($memberTeam->fresh())->not->toBeNull();

    $this->assertDatabaseMissing('team_user', [
        'team_id' => $memberTeam->id,
        'user_id' => $user->id,
    ]);

    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
    ]);
});
