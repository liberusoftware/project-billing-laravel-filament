<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Organizations\Actions\InviteMember;
use Liberu\Foundation\Organizations\Actions\TransferOwnership;
use Liberu\Foundation\Organizations\Models\Organization;
use Liberu\Foundation\Organizations\Models\Team;

uses(RefreshDatabase::class);

it('rejects disallowed roles when inviting a team member', function (): void {
    $action = new InviteMember;

    expect(fn () => $action->handle(1, 'guest@example.test', 'super_admin', 1))
        ->toThrow(InvalidArgumentException::class, 'The role cannot be assigned to a team member.');
});

it('rejects ownership transfer when the successor is not an active team member', function (): void {
    $team = new Team;
    $team->forceFill(['id' => 999, 'user_id' => 10]);

    $action = new TransferOwnership;

    expect(fn () => $action->handle($team, 10, 20, true))
        ->toThrow(RuntimeException::class, 'The successor must be an active member.');
});

it('defines casts and teams relation on Organization model', function (): void {
    $organization = new Organization;

    expect($organization->getCasts())->toHaveKey('archived_at', 'datetime')
        ->and($organization->teams())->toBeInstanceOf(HasMany::class);
});
