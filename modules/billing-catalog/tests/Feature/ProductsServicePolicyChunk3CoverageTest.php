<?php

declare(strict_types=1);

use App\Models\Products_Service;
use App\Models\User;
use App\Policies\Products_ServicePolicy;
use Liberu\Foundation\RolesPermissions\Models\Permission;

afterEach(function (): void {
    setPermissionsTeamId(null);
});

dataset('products_service_policy_abilities', [
    'viewAny' => ['viewAny', 'view_any_products::service', false],
    'view' => ['view', 'view_products::service', true],
    'create' => ['create', 'create_products::service', false],
    'update' => ['update', 'update_products::service', true],
    'delete' => ['delete', 'delete_products::service', true],
    'deleteAny' => ['deleteAny', 'delete_any_products::service', false],
    'forceDelete' => ['forceDelete', 'force_delete_products::service', true],
    'forceDeleteAny' => ['forceDeleteAny', 'force_delete_any_products::service', false],
    'restore' => ['restore', 'restore_products::service', true],
    'restoreAny' => ['restoreAny', 'restore_any_products::service', false],
    'replicate' => ['replicate', 'replicate_products::service', true],
    'reorder' => ['reorder', 'reorder_products::service', false],
]);

it('denies products service policy ability without the required permission and allows it when granted', function (
    string $method,
    string $permission,
    bool $requiresModel,
): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    setPermissionsTeamId($team->id);

    $product = Products_Service::factory()->create(['team_id' => $team->id]);
    $policy = new Products_ServicePolicy();

    $args = $requiresModel ? [$user, $product] : [$user];

    expect($policy->{$method}(...$args))->toBeFalse();

    Permission::findOrCreate($permission, 'web');
    $user->givePermissionTo($permission);

    expect($policy->{$method}(...$args))->toBeTrue();
})->with('products_service_policy_abilities');

it('denies products service policy abilities when permission is granted only in a different team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamA = $user->currentTeam;
    $teamB = $user->ownedTeams()->create([
        'name' => 'Secondary Team',
        'personal_team' => false,
    ]);

    setPermissionsTeamId($teamA->id);
    Permission::findOrCreate('view_any_products::service', 'web');
    $user->givePermissionTo('view_any_products::service');

    $policy = new Products_ServicePolicy();

    expect($policy->viewAny($user))->toBeTrue();

    setPermissionsTeamId($teamB->id);

    expect($policy->viewAny($user->fresh()))->toBeFalse();
});
