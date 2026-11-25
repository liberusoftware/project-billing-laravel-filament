<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Jetstream\AddTeamMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\AddingTeamMember;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

class AddTeamMemberChunk14CoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_owner_can_add_existing_user_with_role(): void
    {
        Event::fake([AddingTeamMember::class, TeamMemberAdded::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $team = $owner->currentTeam;

        (new AddTeamMember())->add($owner, $team, $member->email, 'editor');

        $team = $team->fresh();

        $this->assertTrue($team->hasUser($member));
        $this->assertSame('editor', $team->users->firstWhere('id', $member->id)?->membership?->role);

        Event::assertDispatched(
            AddingTeamMember::class,
            static fn (AddingTeamMember $event): bool => $event->team->is($team) && $event->user->is($member),
        );
        Event::assertDispatched(
            TeamMemberAdded::class,
            static fn (TeamMemberAdded $event): bool => $event->team->is($team) && $event->user->is($member),
        );
    }

    public function test_non_owner_cannot_add_team_member(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $nonOwner = User::factory()->create();
        $candidate = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        (new AddTeamMember())->add($nonOwner, $owner->currentTeam, $candidate->email, 'editor');
    }

    public function test_add_team_member_validates_email_exists(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        try {
            (new AddTeamMember())->add($owner, $owner->currentTeam, 'missing@example.com', 'editor');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame('addTeamMember', $e->errorBag);
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertContains(
                __('We were unable to find a registered user with this email address.'),
                $e->errors()['email'],
            );
        }
    }

    public function test_add_team_member_rejects_user_already_on_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $team = $owner->currentTeam;
        $team->users()->attach($member, ['role' => 'editor']);

        try {
            (new AddTeamMember())->add($owner, $team->fresh(), $member->email, 'editor');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame('addTeamMember', $e->errorBag);
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertContains(
                __('This user already belongs to the team.'),
                $e->errors()['email'],
            );
        }
    }

    public function test_add_team_member_allows_null_role_when_jetstream_has_no_roles(): void
    {
        Event::fake([AddingTeamMember::class, TeamMemberAdded::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $team = $owner->currentTeam;

        $originalRoles = Jetstream::$roles;
        Jetstream::$roles = [];

        try {
            (new AddTeamMember())->add($owner, $team, $member->email, null);
        } finally {
            Jetstream::$roles = $originalRoles;
        }

        $this->assertTrue($team->fresh()->hasUser($member));
    }
}
