<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Models\Permission;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Livewire\Livewire;

beforeEach(function (): void {
    Gate::before(fn (): bool => true);

    $this->admin = User::factory()->create([
        'name' => 'Admin User',
        'email' => 'admin-chunk16@example.com',
    ]);

    $this->team = Team::factory()->create([
        'user_id' => $this->admin->id,
        'name' => 'Alpha Team',
    ]);

    $this->team->users()->attach($this->admin);
    $this->admin->forceFill(['current_team_id' => $this->team->id])->save();

    setPermissionsTeamId($this->team->id);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->team);
});

it('renders the admin ViewUser page with profile, roles, permissions, teams, and 2FA details', function (): void {
    $targetUser = User::factory()->create([
        'name' => 'Alice Smith',
        'email' => 'alice-chunk16@example.com',
        'email_verified_at' => now(),
    ]);

    $ownedTeam = Team::factory()->create([
        'user_id' => $targetUser->id,
        'name' => 'Owned Team',
    ]);

    $this->team->users()->attach($targetUser);
    $targetUser->forceFill([
        'current_team_id' => $this->team->id,
        'two_factor_confirmed_at' => now(),
        'profile_photo_path' => 'profile-photos/alice-avatar.jpg',
    ])->save();

    $role = Role::create([
        'name' => 'editor',
        'guard_name' => 'web',
    ]);

    $permission = Permission::create([
        'name' => 'manage_billing',
        'guard_name' => 'web',
    ]);

    $targetUser->assignRole($role);
    $targetUser->givePermissionTo($permission);

    Livewire::test(ViewUser::class, ['record' => $targetUser->getRouteKey()])
        ->assertOk()
        ->assertActionExists('edit')
        ->assertSee('User Profile')
        ->assertSee('Alice Smith')
        ->assertSee('alice-chunk16@example.com')
        ->assertSee('Roles & Permissions')
        ->assertSee('Editor')
        ->assertSee('manage_billing')
        ->assertSee('Team Information')
        ->assertSee('Alpha Team')
        ->assertSee('Owned Team')
        ->assertSee('Account Information')
        ->assertSee('Yes')
        ->assertSee('profile-photos/alice-avatar.jpg');
});

it('renders the admin ViewUser page placeholders when optional user attributes are empty', function (): void {
    $unverifiedUser = User::factory()->unverified()->create([
        'name' => 'Bob Jones',
        'email' => 'bob-chunk16@example.com',
        'profile_photo_path' => null,
        'current_team_id' => null,
    ]);

    $this->team->users()->attach($unverifiedUser);

    Livewire::test(ViewUser::class, ['record' => $unverifiedUser->getRouteKey()])
        ->assertOk()
        ->assertSee('Bob Jones')
        ->assertSee('bob-chunk16@example.com')
        ->assertSee('Not verified')
        ->assertSee('No roles assigned')
        ->assertSee('No direct permissions')
        ->assertSee('No current team')
        ->assertSee('No owned teams')
        ->assertSee('No custom photo');
});

it('creates a user via the admin UserForm and hashes the password', function (): void {
    Livewire::test(CreateUser::class)
        ->assertOk()
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-chunk16@example.com',
            'password' => 'SecretPass123!',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'jane-chunk16@example.com')->first();

    expect($created)->not->toBeNull()
        ->and($created->name)->toBe('Jane Doe')
        ->and(Hash::check('SecretPass123!', $created->password))->toBeTrue();
});

it('requires a password when creating a user via the admin UserForm', function (): void {
    Livewire::test(CreateUser::class)
        ->assertOk()
        ->fillForm([
            'name' => 'Missing Password User',
            'email' => 'nopass-chunk16@example.com',
            'password' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['password' => 'required']);
});

it('edits a user via the admin UserForm preserving password when blank and hashing when provided', function (): void {
    $originalHash = Hash::make('OriginalPass123!');

    $targetUser = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'edit-chunk16@example.com',
        'password' => $originalHash,
    ]);

    $this->team->users()->attach($targetUser);

    Livewire::test(EditUser::class, ['record' => $targetUser->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'name' => 'Updated Name',
            'email' => 'edit-chunk16@example.com',
            'password' => '',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $targetUser->refresh();

    expect($targetUser->name)->toBe('Updated Name')
        ->and($targetUser->password)->toBe($originalHash);

    Livewire::test(EditUser::class, ['record' => $targetUser->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'name' => 'Updated Name Again',
            'email' => 'edit-chunk16@example.com',
            'password' => 'NewSecretPass456!',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $targetUser->refresh();

    expect($targetUser->name)->toBe('Updated Name Again')
        ->and(Hash::check('NewSecretPass456!', $targetUser->password))->toBeTrue();
});
