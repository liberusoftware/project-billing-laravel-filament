<?php

declare(strict_types=1);

use App\Filament\App\Pages\CreateTeam;
use App\Filament\App\Pages\EditProfile;
use App\Filament\App\Pages\EditTeam;
use App\Filament\App\Pages\PersonalAccessTokensPage;
use App\Filament\App\Pages\UpdateProfileInformationPage;
use App\Filament\App\Resources\Users\Pages\EditUser as AppEditUser;
use App\Filament\App\Resources\Users\Pages\ListUsers as AppListUsers;
use App\Filament\App\Resources\Users\UserResource as AppUserResource;
use App\Filament\Client\Pages\Profile as ClientProfile;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

it('covers App CreateTeam page form, registration handler, breadcrumbs, and label', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Gate::before(fn (): bool => true);

    $page = new class() extends CreateTeam
    {
        public function registerTeam(array $data): Team
        {
            /** @var Team $team */
            $team = $this->handleRegistration($data);

            return $team;
        }
    };

    $schema = $page->form(Schema::make($page));
    $created = $page->registerTeam(['name' => 'App Created Team']);
    $breadcrumbs = $page->getBreadcrumbs();

    expect(CreateTeam::getLabel())->toBe('Create Team')
        ->and($schema->getComponents())->toHaveCount(1)
        ->and($created)->toBeInstanceOf(Team::class)
        ->and($created->name)->toBe('App Created Team')
        ->and($breadcrumbs)->toContain('Create Team');
});

it('covers App EditProfile page mount, form, submit, and breadcrumbs', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'name' => 'Initial Profile Name',
        'email' => 'initial-profile@example.com',
    ]);
    $this->actingAs($user);

    $page = new class() extends EditProfile
    {
        public ?string $name = null;

        public ?string $email = null;
    };

    $page->mount();

    expect($page->name)->toBe('Initial Profile Name')
        ->and($page->email)->toBe('initial-profile@example.com')
        ->and($page->getBreadcrumbs())->toContain('Edit Profile');

    $page->form->fill([
        'name' => 'Updated Profile Name',
        'email' => 'updated-profile@example.com',
    ]);
    $page->submit();

    $user->refresh();
    expect($user->name)->toBe('Updated Profile Name')
        ->and($user->email)->toBe('updated-profile@example.com');

    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();
    $notifications = $mailbox->pull();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['title'])->toBe('Your profile has been updated.');
});

it('covers App EditTeam page label, form, submit, and breadcrumbs', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $panel->boot();

    Route::get('/_test/filament/edit-team/{team}', static fn () => 'ok')
        ->name('filament.pages.edit-team');
    app('router')->getRoutes()->refreshNameLookups();

    $page = new class() extends EditTeam
    {
        /** @var array<string, string> */
        protected $rules = [
            'name' => 'required|string|max:255',
        ];
    };

    $schema = $page->form(Schema::make($page));
    $page->name = 'New Managed Team';
    $response = $page->submit();

    $createdTeam = Team::query()->where('name', 'New Managed Team')->first();

    expect(EditTeam::getLabel())->toBe('Edit Team')
        ->and($schema->getComponents())->toHaveCount(1)
        ->and($page->getBreadcrumbs())->toContain('Create Team')
        ->and($createdTeam)->not->toBeNull()
        ->and($createdTeam->personal_team)->toBeFalse()
        ->and($user->fresh()->current_team_id)->toBe($createdTeam->id)
        ->and($response)->toBeInstanceOf(RedirectResponse::class);
});

it('covers App PersonalAccessTokensPage mount, token creation, deletion, heading, and navigation', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $page = new PersonalAccessTokensPage();
    $page->mount();

    expect(PersonalAccessTokensPage::shouldRegisterNavigation())->toBeTrue()
        ->and($page->getHeading())->toBe('Personal Access Tokens')
        ->and($page->user->is($user))->toBeTrue();

    $page->createApiToken('deployment-token');

    expect($user->tokens()->where('name', 'deployment-token')->exists())->toBeTrue();

    $page->deleteApiToken('deployment-token');

    expect($user->tokens()->where('name', 'deployment-token')->exists())->toBeFalse();
});

it('covers App UpdateProfileInformationPage mount, form, submit, heading, and navigation', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'name' => 'Old Info Name',
        'email' => 'old-info@example.com',
    ]);
    $this->actingAs($user);

    $page = new UpdateProfileInformationPage();
    $page->mount();

    expect(UpdateProfileInformationPage::shouldRegisterNavigation())->toBeTrue()
        ->and($page->getHeading())->toBe('Profile')
        ->and($page->name)->toBe('Old Info Name')
        ->and($page->email)->toBe('old-info@example.com');

    $page->form->fill([
        'name' => 'New Info Name',
        'email' => 'new-info@example.com',
    ]);
    $page->submit();

    $user->refresh();
    expect($user->name)->toBe('New Info Name')
        ->and($user->email)->toBe('new-info@example.com')
        ->and(session('status'))->toBe('Your profile has been updated.');
});

it('covers App UserResource form, table, ListUsers, and EditUser pages', function (): void {
    Gate::before(fn (): bool => true);

    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->create();
    $team->users()->syncWithoutDetaching([$owner->id, $member->id]);

    $this->actingAs($owner);
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);

    $schema = AppUserResource::form(Schema::make());
    $table = AppUserResource::table(Table::make(Mockery::mock(HasTable::class)));

    expect($schema->getComponents())->toHaveCount(1)
        ->and($schema->getComponents()[0])->toBeInstanceOf(Tabs::class)
        ->and($table->getColumns())->toHaveCount(7);

    Livewire::test(AppListUsers::class)
        ->assertOk();

    Livewire::test(AppEditUser::class, ['record' => $member->getRouteKey()])
        ->assertOk();
});

it('covers Client Profile page mount, form, profile update, and password change branches', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'name' => 'Client Original',
        'email' => 'client-original@example.com',
        'password' => Hash::make('OldStr0ng-Pass!99'),
    ]);
    $this->actingAs($user);

    $makePage = static fn (): ClientProfile => new class() extends ClientProfile
    {
        public ?string $name = null;

        public ?string $email = null;

        public ?string $current_password = null;

        public ?string $password = null;

        public ?string $password_confirmation = null;
    };

    expect(ClientProfile::shouldRegisterNavigation())->toBeTrue();

    // 1. Update name & email without changing password
    $page = $makePage();
    $page->mount();
    expect($page->name)->toBe('Client Original')
        ->and($page->email)->toBe('client-original@example.com');

    $page->form->fill([
        'name' => 'Client Updated',
        'email' => 'client-updated@example.com',
        'password' => '',
    ]);
    $page->submit();

    $user->refresh();
    expect($user->name)->toBe('Client Updated')
        ->and($user->email)->toBe('client-updated@example.com')
        ->and(Hash::check('OldStr0ng-Pass!99', $user->password))->toBeTrue();

    // 2. Attempt password change with incorrect current_password
    $wrongPassPage = $makePage();
    $wrongPassPage->mount();
    $wrongPassPage->form->fill([
        'name' => 'Client Updated',
        'email' => 'client-updated@example.com',
        'current_password' => 'WrongPassword!123',
        'password' => 'Str0ng-NewPassphrase!9',
        'password_confirmation' => 'Str0ng-NewPassphrase!9',
    ]);
    $wrongPassPage->submit();

    expect($wrongPassPage->getErrorBag()->has('current_password'))->toBeTrue()
        ->and(Hash::check('OldStr0ng-Pass!99', $user->fresh()->password))->toBeTrue();

    // 3. Change password with valid current_password
    $validPassPage = $makePage();
    $validPassPage->mount();
    $validPassPage->form->fill([
        'name' => 'Client Updated Again',
        'email' => 'client-updated@example.com',
        'current_password' => 'OldStr0ng-Pass!99',
        'password' => 'Str0ng-NewPassphrase!9',
        'password_confirmation' => 'Str0ng-NewPassphrase!9',
    ]);
    $validPassPage->submit();

    $user->refresh();
    expect($user->name)->toBe('Client Updated Again')
        ->and(Hash::check('Str0ng-NewPassphrase!9', $user->password))->toBeTrue();
});
