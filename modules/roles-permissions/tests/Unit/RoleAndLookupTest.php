<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\RolesPermissions\Contracts\PrivilegedActor;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Liberu\Foundation\RolesPermissions\Services\AnyTeamRoleLookup;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('exposes the team relationship on Role', function (): void {
    $role = new Role();

    expect($role->team())->toBeInstanceOf(BelongsTo::class);
});

it('checks whether a privileged actor holds any role across teams', function (): void {
    $lookup = new AnyTeamRoleLookup();
    $actor = new class() implements PrivilegedActor
    {
        public function authorizationIdentifier(): int|string
        {
            return 999999;
        }

        public function authorizationType(): string
        {
            return 'App\\Models\\User';
        }

        public function hasRoleInAnyTeam(string|array $roles): bool
        {
            return false;
        }

        public function isSuperAdmin(): bool
        {
            return false;
        }
    };

    expect($lookup->hasRoleInAnyTeam($actor, []))->toBeFalse();

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'any-team-role-test',
        'guard_name' => 'web',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($lookup->hasRoleInAnyTeam($actor, 'any-team-role-test'))->toBeFalse();

    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_type' => $actor->authorizationType(),
        'model_id' => $actor->authorizationIdentifier(),
        'team_id' => 1,
    ]);

    expect($lookup->hasRoleInAnyTeam($actor, ['any-team-role-test']))->toBeTrue();
});
