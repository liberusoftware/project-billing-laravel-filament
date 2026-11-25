<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Filament\App\Pages\AccountSetup;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\NotificationMailbox;
use Filament\Schemas\Components\Wizard;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

$makeTestableAccountSetup = static function (): AccountSetup {
    return new class extends AccountSetup
    {
        public ?string $team_name = null;

        public ?string $organisation_type = null;

        public ?string $country = null;

        public ?string $currency = null;

        public ?string $timezone = null;

        public ?string $stripe_secret = null;

        public ?string $paddle_token = null;

        public ?string $tax_api_key = null;

        public ?string $resellerclub_api_key = null;

        public mixed $resellerclub_auth_userid = null;

        public ?string $enom_username = null;

        public ?string $enom_password = null;

        public ?string $github_client_id = null;

        public ?string $github_client_secret = null;

        public ?string $google_client_id = null;

        public ?string $google_client_secret = null;

        public ?string $facebook_client_id = null;

        public ?string $facebook_client_secret = null;

        public ?string $twitter_client_id = null;

        public ?string $twitter_client_secret = null;
    };
};

it('registers account setup in navigation', function (): void {
    expect(AccountSetup::shouldRegisterNavigation())->toBeTrue();
});

it('mounts and renders the account setup page for the workspace owner', function () use ($makeTestableAccountSetup): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create([
        'user_id' => $owner->id,
        'name' => 'Acme Workspace',
        'personal_team' => true,
        'setup_configuration' => null,
    ]);
    $owner->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($owner);
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);

    Livewire::test(AccountSetup::class)
        ->assertOk();

    $team->forceFill([
        'organisation_type' => OrganisationType::Reseller->value,
        'setup_configuration' => [
            'currency' => 'GBP',
            'country' => 'GB',
            'timezone' => 'Europe/London',
        ],
    ])->save();

    $page = $makeTestableAccountSetup();
    $page->mount();

    expect($page->team_name)->toBe('Acme Workspace')
        ->and($page->organisation_type)->toBe('reseller')
        ->and($page->currency)->toBe('GBP')
        ->and($page->country)->toBe('GB')
        ->and($page->timezone)->toBe('Europe/London')
        ->and($page->stripe_secret)->toBeNull()
        ->and($page->paddle_token)->toBeNull();

    $flatComponents = $page->form->getFlatComponents(withHidden: true);
    $timezoneField = collect($flatComponents)->first(
        static fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'timezone',
    );
    $wizard = collect($page->form->getComponents(withHidden: true))->first(
        static fn (mixed $component): bool => $component instanceof Wizard,
    );

    expect($timezoneField)->toBeInstanceOf(Select::class)
        ->and($timezoneField->getOptions())->toHaveKey('UTC', 'UTC')
        ->and($timezoneField->getOptions())->toHaveKey('Europe/London', 'Europe/London')
        ->and($wizard)->toBeInstanceOf(Wizard::class)
        ->and($wizard->getDefaultChildComponents())->toHaveCount(4);
});

it('forbids mounting account setup when the user has no current workspace or does not own the team', function (): void {
    $userWithoutTeam = User::factory()->create(['current_team_id' => null]);
    $this->actingAs($userWithoutTeam);

    try {
        (new AccountSetup)->mount();
        $this->fail('Expected HttpException when user has no current workspace.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403)
            ->and($exception->getMessage())->toBe('A current workspace is required.');
    }

    $owner = User::factory()->create();
    $team = Team::factory()->create([
        'user_id' => $owner->id,
        'personal_team' => true,
    ]);
    $nonOwner = User::factory()->create(['current_team_id' => $team->id]);
    $this->actingAs($nonOwner);

    try {
        (new AccountSetup)->mount();
        $this->fail('Expected HttpException when user does not own the current workspace.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('saves workspace defaults, merges non-empty credentials, preserves existing configuration, and sends a notification', function () use ($makeTestableAccountSetup): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create([
        'user_id' => $owner->id,
        'name' => 'Initial Workspace',
        'personal_team' => true,
        'setup_configuration' => [
            'existing_key' => 'keep-me',
            'paddle_token' => 'existing-paddle-token',
        ],
        'setup_completed_at' => null,
    ]);
    $owner->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($owner);

    $page = $makeTestableAccountSetup();
    $page->mount();
    $page->form->fill([
        'team_name' => 'Updated Workspace',
        'organisation_type' => OrganisationType::Partner->value,
        'country' => 'GB',
        'currency' => 'GBP',
        'timezone' => 'Europe/London',
        'stripe_secret' => 'sk_test_123456',
        'paddle_token' => '',
        'tax_api_key' => 'tax_api_123',
        'resellerclub_api_key' => 'rc_api_key',
        'resellerclub_auth_userid' => '98765',
        'enom_username' => 'enom_user',
        'enom_password' => 'enom_pass',
        'github_client_id' => 'gh_client_id',
        'github_client_secret' => 'gh_client_secret',
        'google_client_id' => 'google_client_id',
        'google_client_secret' => 'google_client_secret',
        'facebook_client_id' => 'fb_app_id',
        'facebook_client_secret' => 'fb_app_secret',
        'twitter_client_id' => 'tw_client_id',
        'twitter_client_secret' => 'tw_client_secret',
    ]);

    $page->save();

    $fresh = $team->fresh();

    expect($fresh->name)->toBe('Updated Workspace')
        ->and($fresh->organisation_type)->toBe(OrganisationType::Partner)
        ->and($fresh->setup_completed_at)->not->toBeNull()
        ->and($fresh->setup_configuration)->toMatchArray([
            'existing_key' => 'keep-me',
            'paddle_token' => 'existing-paddle-token',
            'country' => 'GB',
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
            'stripe_secret' => 'sk_test_123456',
            'tax_api_key' => 'tax_api_123',
            'resellerclub_api_key' => 'rc_api_key',
            'resellerclub_auth_userid' => '98765',
            'enom_username' => 'enom_user',
            'enom_password' => 'enom_pass',
            'github_client_id' => 'gh_client_id',
            'github_client_secret' => 'gh_client_secret',
            'google_client_id' => 'google_client_id',
            'google_client_secret' => 'google_client_secret',
            'facebook_client_id' => 'fb_app_id',
            'facebook_client_secret' => 'fb_app_secret',
            'twitter_client_id' => 'tw_client_id',
            'twitter_client_secret' => 'tw_client_secret',
        ]);

    $mailbox = app(NotificationMailbox::class);
    expect($mailbox->hasPendingNotifications())->toBeTrue();

    $mailbox->flush();
    $notifications = $mailbox->pull();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['title'])->toBe('Workspace setup saved')
        ->and($notifications[0]['status'])->toBe('success');
});

it('saves workspace setup with null initial configuration and omits blank credentials', function () use ($makeTestableAccountSetup): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create([
        'user_id' => $owner->id,
        'name' => 'Minimal Workspace',
        'personal_team' => true,
        'setup_configuration' => null,
        'setup_completed_at' => null,
    ]);
    $owner->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($owner);

    $page = $makeTestableAccountSetup();
    $page->mount();
    $page->form->fill([
        'team_name' => 'Minimal Workspace Saved',
        'organisation_type' => OrganisationType::Company->value,
        'country' => null,
        'currency' => 'USD',
        'timezone' => 'UTC',
        'stripe_secret' => null,
        'paddle_token' => '',
        'tax_api_key' => null,
        'resellerclub_api_key' => null,
        'resellerclub_auth_userid' => null,
        'enom_username' => null,
        'enom_password' => null,
        'github_client_id' => null,
        'github_client_secret' => null,
        'google_client_id' => null,
        'google_client_secret' => null,
        'facebook_client_id' => null,
        'facebook_client_secret' => null,
        'twitter_client_id' => null,
        'twitter_client_secret' => null,
    ]);

    $page->save();

    $fresh = $team->fresh();

    expect($fresh->name)->toBe('Minimal Workspace Saved')
        ->and($fresh->organisation_type)->toBe(OrganisationType::Company)
        ->and($fresh->setup_completed_at)->not->toBeNull()
        ->and($fresh->setup_configuration)->toBe([
            'country' => null,
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
});
