<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Teams\Pages\EditTeam;
use App\Filament\Admin\Resources\Teams\Pages\ListTeams;
use App\Filament\Admin\Resources\Teams\TeamResource;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate('super_admin', 'web');

    $this->admin = User::factory()->withPersonalTeam()->create();
    $this->admin->assignRole('super_admin');

    $this->team = $this->admin->currentTeam;
    $this->team->users()->syncWithoutDetaching([$this->admin->id]);

    Gate::before(fn () => true);

    $this->actingAs($this->admin);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($this->team);
});

it('renders the admin teams list page and configures the teams table', function (): void {
    $otherTeam = Team::factory()->create([
        'user_id' => $this->admin->id,
        'personal_team' => false,
    ]);

    expect(TeamResource::isScopedToTenant())->toBeFalse();

    Livewire::test(ListTeams::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->team, $otherTeam]);
});

it('renders the admin edit team page with header actions', function (): void {
    $editableTeam = Team::factory()->create([
        'user_id' => $this->admin->id,
        'personal_team' => false,
    ]);

    Livewire::test(EditTeam::class, ['record' => $editableTeam->getRouteKey()])
        ->assertOk();
});

it('renders the admin users list page with header actions', function (): void {
    $member = User::factory()->create();
    $this->team->users()->syncWithoutDetaching([$member->id]);

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->admin, $member]);
});

it('renders the admin edit user page with header actions', function (): void {
    $member = User::factory()->create();
    $this->team->users()->syncWithoutDetaching([$member->id]);

    Livewire::test(EditUser::class, ['record' => $member->getRouteKey()])
        ->assertOk();
});
