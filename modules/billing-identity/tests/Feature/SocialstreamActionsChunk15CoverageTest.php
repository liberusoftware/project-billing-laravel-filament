<?php

declare(strict_types=1);

use App\Actions\Socialstream\CreateConnectedAccount;
use App\Actions\Socialstream\CreateUserFromProvider;
use App\Actions\Socialstream\GenerateRedirectForProvider;
use App\Actions\Socialstream\HandleInvalidState;
use App\Actions\Socialstream\ResolveSocialiteUser;
use App\Actions\Socialstream\UpdateConnectedAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JoelButcher\Socialstream\ConnectedAccount as SocialstreamConnectedAccount;
use JoelButcher\Socialstream\Features as SocialstreamFeatures;
use Laravel\Jetstream\Features as JetstreamFeatures;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\One\User as OneUser;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as TwoUser;
use Symfony\Component\HttpFoundation\RedirectResponse;

it('creates a connected account from an OAuth 2 provider user with expiry', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    try {
        $user = User::factory()->create();
        $providerUser = TwoUser::fake([
            'id' => 'gh-12345',
            'name' => 'Jane OAuth2',
            'nickname' => 'jane2',
            'email' => 'jane2@example.com',
            'avatar' => 'https://example.com/jane.png',
            'token' => 'oauth2-access-token',
            'refreshToken' => 'oauth2-refresh-token',
            'expiresIn' => 3600,
        ]);

        $account = (new CreateConnectedAccount())->create($user, 'github', $providerUser);

        expect($account)->toBeInstanceOf(SocialstreamConnectedAccount::class)
            ->and($account->provider)->toBe('github')
            ->and($account->provider_id)->toBe('gh-12345')
            ->and($account->name)->toBe('Jane OAuth2')
            ->and($account->nickname)->toBe('jane2')
            ->and($account->email)->toBe('jane2@example.com')
            ->and($account->avatar_path)->toBe('https://example.com/jane.png')
            ->and($account->token)->toBe('oauth2-access-token')
            ->and($account->secret)->toBeNull()
            ->and($account->refresh_token)->toBe('oauth2-refresh-token')
            ->and($account->expires_at?->toDateTimeString())->toBe('2026-05-01 13:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

it('creates a connected account from an OAuth 1 provider user without expiry', function (): void {
    $user = User::factory()->create();
    $providerUser = OneUser::fake([
        'id' => 'tw-98765',
        'name' => 'John OAuth1',
        'nickname' => 'john1',
        'email' => 'john1@example.com',
        'avatar' => 'https://example.com/john.png',
        'token' => 'oauth1-token',
        'tokenSecret' => 'oauth1-secret',
    ]);

    $account = (new CreateConnectedAccount())->create($user, 'twitter', $providerUser);

    expect($account)->toBeInstanceOf(SocialstreamConnectedAccount::class)
        ->and($account->provider)->toBe('twitter')
        ->and($account->provider_id)->toBe('tw-98765')
        ->and($account->token)->toBe('')
        ->and($account->secret)->toBe('oauth1-secret')
        ->and($account->refresh_token)->toBeNull()
        ->and($account->expires_at)->toBeNull();
});

it('creates a user from a provider with avatar import and connected account', function (): void {
    Storage::fake('public');
    Http::fake([
        'https://example.com/provider-avatar.png' => Http::response('fake-image-bytes', 200),
    ]);

    config()->set('jetstream.features', array_values(array_unique([
        ...config('jetstream.features', []),
        JetstreamFeatures::profilePhotos(),
    ])));
    config()->set('socialstream.features', array_values(array_unique([
        ...config('socialstream.features', []),
        SocialstreamFeatures::providerAvatars(),
    ])));

    $providerUser = TwoUser::fake([
        'id' => 'prov-user-1',
        'name' => 'Alice Provider',
        'nickname' => 'alicep',
        'email' => 'alice-provider@example.com',
        'avatar' => 'https://example.com/provider-avatar.png',
        'token' => 'alice-token',
        'refreshToken' => 'alice-refresh',
        'expiresIn' => 1800,
    ]);

    $action = new CreateUserFromProvider(new CreateConnectedAccount());
    $user = $action->create('github', $providerUser);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('Alice Provider')
        ->and($user->email)->toBe('alice-provider@example.com')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->profile_photo_path)->not->toBeNull()
        ->and($user->connectedAccounts()->where('provider', 'github')->where('provider_id', 'prov-user-1')->exists())->toBeTrue();
});

it('creates a user from a provider falling back to nickname when name is null', function (): void {
    $providerUser = TwoUser::fake([
        'id' => 'prov-user-2',
        'name' => null,
        'nickname' => 'nick_only',
        'email' => 'nick-only@example.com',
        'avatar' => null,
        'token' => 'nick-token',
        'refreshToken' => null,
        'expiresIn' => null,
    ]);

    $action = new CreateUserFromProvider(new CreateConnectedAccount());
    $user = $action->create('github', $providerUser);

    expect($user->name)->toBe('nick_only')
        ->and($user->email)->toBe('nick-only@example.com')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->profile_photo_path)->toBeNull()
        ->and($user->connectedAccounts()->count())->toBe(1);
});

it('generates a redirect for a provider and remembers previous url when enabled', function (): void {
    $expectedRedirect = new RedirectResponse('https://github.com/login/oauth/authorize');

    $driver = Mockery::mock(SocialiteProvider::class);
    $driver->shouldReceive('redirect')->once()->andReturn($expectedRedirect);

    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->once()->with('github')->andReturn($driver);

    $this->app->instance(SocialiteFactory::class, $socialite);

    config()->set('socialstream.features', [SocialstreamFeatures::rememberSession()]);

    $response = (new GenerateRedirectForProvider())->generate('github');

    expect($response)->toBe($expectedRedirect)
        ->and(session()->has('socialstream.previous_url'))->toBeTrue();
});

it('generates a redirect for a provider without storing session url when remember session is disabled', function (): void {
    $expectedRedirect = new RedirectResponse('https://github.com/login/oauth/authorize');

    $driver = Mockery::mock(SocialiteProvider::class);
    $driver->shouldReceive('redirect')->once()->andReturn($expectedRedirect);

    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->once()->with('github')->andReturn($driver);

    $this->app->instance(SocialiteFactory::class, $socialite);

    config()->set('socialstream.features', []);
    session()->forget('socialstream.previous_url');

    $response = (new GenerateRedirectForProvider())->generate('github');

    expect($response)->toBe($expectedRedirect)
        ->and(session()->has('socialstream.previous_url'))->toBeFalse();
});

it('rethrows invalid state exceptions in HandleInvalidState', function (): void {
    $exception = new InvalidStateException('Invalid OAuth state');

    expect(fn () => (new HandleInvalidState())->handle($exception))
        ->toThrow(InvalidStateException::class, 'Invalid OAuth state');
});

it('resolves the socialite user for a provider', function (): void {
    $expectedUser = TwoUser::fake([
        'id' => 'resolved-42',
        'email' => 'resolved@example.com',
    ]);

    $driver = Mockery::mock(SocialiteProvider::class);
    $driver->shouldReceive('user')->once()->andReturn($expectedUser);

    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->shouldReceive('driver')->once()->with('github')->andReturn($driver);

    $this->app->instance(SocialiteFactory::class, $socialite);

    $resolved = (new ResolveSocialiteUser())->resolve('github');

    expect($resolved)->toBe($expectedUser);
});

it('updates an existing connected account from OAuth 2 and OAuth 1 provider users', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    try {
        $user = User::factory()->create();
        $account = (new CreateConnectedAccount())->create(
            $user,
            'github',
            TwoUser::fake([
                'id' => 'upd-1',
                'name' => 'Initial Name',
                'nickname' => 'initial',
                'email' => 'initial@example.com',
                'avatar' => 'https://example.com/old.png',
                'token' => 'old-token',
                'refreshToken' => 'old-refresh',
                'expiresIn' => 600,
            ])
        );

        $updater = new UpdateConnectedAccount();

        $updatedTwo = $updater->update(
            $user,
            $account,
            'github',
            TwoUser::fake([
                'id' => 'upd-1',
                'name' => 'Updated Name',
                'nickname' => 'updated',
                'email' => 'updated@example.com',
                'avatar' => 'https://example.com/new.png',
                'token' => 'new-token',
                'refreshToken' => 'new-refresh',
                'expiresIn' => 7200,
            ])
        );

        expect($updatedTwo->fresh())
            ->name->toBe('Updated Name')
            ->nickname->toBe('updated')
            ->email->toBe('updated@example.com')
            ->avatar_path->toBe('https://example.com/new.png')
            ->token->toBe('new-token')
            ->secret->toBeNull()
            ->refresh_token->toBe('new-refresh')
            ->and($updatedTwo->fresh()->expires_at?->toDateTimeString())->toBe('2026-05-01 14:00:00');

        $updatedOne = $updater->update(
            $user,
            $account,
            'twitter',
            OneUser::fake([
                'id' => 'upd-1',
                'name' => 'OAuth1 Updated',
                'nickname' => 'oauth1upd',
                'email' => 'oauth1@example.com',
                'avatar' => 'https://example.com/oauth1.png',
                'token' => 'ignored-oauth1-token',
                'tokenSecret' => 'updated-oauth1-secret',
            ])
        );

        expect($updatedOne->fresh())
            ->name->toBe('OAuth1 Updated')
            ->token->toBe('')
            ->secret->toBe('updated-oauth1-secret')
            ->refresh_token->toBeNull()
            ->expires_at->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});
