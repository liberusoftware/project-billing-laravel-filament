<?php

declare(strict_types=1);

use App\Models\Role as AppRole;
use App\Models\Team;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\TeamPolicy;
use App\Policies\UserPolicy;
use Liberu\Foundation\RolesPermissions\Models\Permission;
use Liberu\Foundation\RolesPermissions\Models\Role;

it('authorizes all RolePolicy abilities based on admin and super_admin roles', function () {
    $team = Team::factory()->create();
    setPermissionsTeamId($team->id);

    $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole($superAdminRole);

    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $regular = User::factory()->create();

    $policy = new RolePolicy();
    $targetRole = new AppRole(['name' => 'editor', 'guard_name' => 'web']);

    // viewAny & view: allowed for admin and super_admin, denied for regular
    expect($policy->viewAny($superAdmin))->toBeTrue()
        ->and($policy->viewAny($admin))->toBeTrue()
        ->and($policy->viewAny($regular))->toBeFalse()
        ->and($policy->view($superAdmin, $targetRole))->toBeTrue()
        ->and($policy->view($admin, $targetRole))->toBeTrue()
        ->and($policy->view($regular, $targetRole))->toBeFalse();

    // super_admin-only abilities
    expect($policy->create($superAdmin))->toBeTrue()
        ->and($policy->create($admin))->toBeFalse()
        ->and($policy->update($superAdmin, $targetRole))->toBeTrue()
        ->and($policy->update($admin, $targetRole))->toBeFalse()
        ->and($policy->delete($superAdmin, $targetRole))->toBeTrue()
        ->and($policy->delete($admin, $targetRole))->toBeFalse()
        ->and($policy->deleteAny($superAdmin))->toBeTrue()
        ->and($policy->deleteAny($admin))->toBeFalse()
        ->and($policy->forceDelete($superAdmin, $targetRole))->toBeTrue()
        ->and($policy->forceDelete($admin, $targetRole))->toBeFalse()
        ->and($policy->forceDeleteAny($superAdmin))->toBeTrue()
        ->and($policy->forceDeleteAny($admin))->toBeFalse()
        ->and($policy->restore($superAdmin, $targetRole))->toBeTrue()
        ->and($policy->restore($admin, $targetRole))->toBeFalse()
        ->and($policy->restoreAny($superAdmin))->toBeTrue()
        ->and($policy->restoreAny($admin))->toBeFalse()
        ->and($policy->replicate($superAdmin, $targetRole))->toBeTrue()
        ->and($policy->replicate($admin, $targetRole))->toBeFalse()
        ->and($policy->reorder($superAdmin))->toBeTrue()
        ->and($policy->reorder($admin))->toBeFalse();
});

it('authorizes TeamPolicy view for team members and denies strangers', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $stranger = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);
    $team->users()->attach($member, ['role' => 'editor']);

    $policy = new TeamPolicy();

    expect($policy->view($owner, $team))->toBeTrue()
        ->and($policy->view($member->fresh(), $team))->toBeTrue()
        ->and($policy->view($stranger, $team))->toBeFalse();
});

it('authorizes all UserPolicy abilities via permissions', function () {
    $team = Team::factory()->create();
    setPermissionsTeamId($team->id);

    $user = User::factory()->create();
    $policy = new UserPolicy();

    $abilities = [
        'viewAny' => 'view_any:user',
        'view' => 'view:user',
        'create' => 'create:user',
        'update' => 'update:user',
        'delete' => 'delete:user',
        'restore' => 'restore:user',
        'forceDelete' => 'force_delete:user',
        'forceDeleteAny' => 'force_delete_any:user',
        'restoreAny' => 'restore_any:user',
        'replicate' => 'replicate:user',
        'reorder' => 'reorder:user',
    ];

    foreach ($abilities as $method => $permissionName) {
        expect($policy->{$method}($user))->toBeFalse();

        $permission = Permission::firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);

        expect($policy->{$method}($user->fresh()))->toBeTrue();
    }
});
