<?php

declare(strict_types=1);

use App\Exceptions\Handler;
use App\Http\Middleware\EnsureWorkspaceSetup;
use App\Http\Middleware\RequireTwoFactorEnabled;
use App\Http\Middleware\ScreeningDataEncryptor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustHosts;
use App\Models\BulkOperation;
use App\Models\Menu;
use App\Models\Team;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SocialstreamServiceProvider;
use App\Providers\TeamServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

it('logs stack overflow and container recursion errors and guards against recursive handling', function (): void {
    Log::spy();

    $handler = new Handler(app());
    $handler->register();

    $handler->report(new Error('Maximum call stack size of 8339456 bytes reached'));
    $handler->report(new Error('Fatal error in Container.php line 1048'));
    $handler->report(new Error('Unrelated fatal error'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Recursion or stack overflow detected'
            && isset($context['message'], $context['file'], $context['line'], $context['trace']))
        ->twice();

    $handlingError = new ReflectionProperty(Handler::class, 'handlingError');
    $handlingError->setValue($handler, true);

    try {
        $handler->report(new Error('Maximum call stack size during recursion'));
    } finally {
        $handlingError->setValue($handler, false);
    }

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => $message === 'Recursion or stack overflow detected')
        ->twice();
});

it('revokes only the current API token via AuthController', function (): void {
    $user = User::factory()->create();
    $token1 = $user->createToken('device-1', ['*']);
    $user->createToken('device-2', ['*']);

    $response = $this->withToken($token1->plainTextToken)
        ->deleteJson('/api/auth/token');

    $response->assertOk()
        ->assertExactJson(['message' => 'Token revoked successfully']);

    expect($user->tokens()->count())->toBe(1);
});

it('revokes all API tokens via AuthController', function (): void {
    $user = User::factory()->create();
    $token1 = $user->createToken('device-1', ['*']);
    $user->createToken('device-2', ['*']);

    $response = $this->withToken($token1->plainTextToken)
        ->deleteJson('/api/auth/tokens');

    $response->assertOk()
        ->assertExactJson(['message' => 'All tokens revoked successfully']);

    expect($user->tokens()->count())->toBe(0);
});

it('redirects owned teams with incomplete workspace setup and applies temporary config overrides', function (): void {
    $middleware = new EnsureWorkspaceSetup();

    // Guest request passes straight through
    $guestReq = Request::create('/app/dashboard', 'GET');
    $guestRes = $middleware->handle($guestReq, fn () => new Response('guest-ok'));
    expect($guestRes->getContent())->toBe('guest-ok');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create([
        'user_id' => $owner->id,
        'setup_completed_at' => null,
        'setup_configuration' => [
            'stripe_secret' => 'sk_test_chunk0',
            'paddle_token' => '',
            'tax_api_key' => null,
            'github_client_id' => 'gh_client_chunk0',
        ],
    ]);

    $owner->forceFill(['current_team_id' => $team->id])->save();
    $owner->setRelation('currentTeam', $team);

    // Owner on non-setup path is redirected to account setup
    $redirectReq = Request::create('/app/dashboard', 'GET');
    $redirectReq->setUserResolver(fn () => $owner);
    $redirectRes = $middleware->handle($redirectReq, fn () => new Response('should-not-run'));
    expect($redirectRes->isRedirect())->toBeTrue()
        ->and($redirectRes->headers->get('Location'))->toContain('/app/account-setup');

    // Owner on setup path is not redirected
    $setupReq = Request::create('/app/account-setup', 'GET');
    $setupReq->setUserResolver(fn () => $owner);
    $setupRes = $middleware->handle($setupReq, fn () => new Response('setup-page'));
    expect($setupRes->getContent())->toBe('setup-page');

    // Non-owner member is not redirected even when setup is incomplete
    $member->forceFill(['current_team_id' => $team->id])->save();
    $member->setRelation('currentTeam', $team);
    $memberReq = Request::create('/app/dashboard', 'GET');
    $memberReq->setUserResolver(fn () => $member);
    $memberRes = $middleware->handle($memberReq, fn () => new Response('member-ok'));
    expect($memberRes->getContent())->toBe('member-ok');

    // Completed setup applies non-empty overrides and restores originals in finally
    $originalStripe = config('services.stripe.secret');
    $originalGithub = config('services.github.client_id');
    $team->forceFill(['setup_completed_at' => now()])->save();

    $observed = [];
    $completedReq = Request::create('/app/dashboard', 'GET');
    $completedReq->setUserResolver(fn () => $owner);
    $completedRes = $middleware->handle($completedReq, function () use (&$observed): Response {
        $observed['stripe'] = config('services.stripe.secret');
        $observed['github'] = config('services.github.client_id');

        return new Response('configured-ok');
    });

    expect($completedRes->getContent())->toBe('configured-ok')
        ->and($observed['stripe'])->toBe('sk_test_chunk0')
        ->and($observed['github'])->toBe('gh_client_chunk0')
        ->and(config('services.stripe.secret'))->toBe($originalStripe)
        ->and(config('services.github.client_id'))->toBe($originalGithub);
});

it('enforces two-factor authentication in RequireTwoFactorEnabled middleware', function (): void {
    $middleware = new RequireTwoFactorEnabled();

    // Unauthenticated user is redirected to profile.show
    $guestReq = Request::create('/protected', 'GET');
    $guestRes = $middleware->handle($guestReq, fn () => new Response('ok'));
    expect($guestRes->isRedirect(route('profile.show')))->toBeTrue();

    // Authenticated user without 2FA secret is redirected
    $userWithout2fa = User::factory()->create(['two_factor_secret' => null]);
    $no2faReq = Request::create('/protected', 'GET');
    $no2faReq->setUserResolver(fn () => $userWithout2fa);
    $no2faRes = $middleware->handle($no2faReq, fn () => new Response('ok'));
    expect($no2faRes->isRedirect(route('profile.show')))->toBeTrue();

    // Authenticated user with 2FA secret passes through
    $userWith2fa = User::factory()->create(['two_factor_secret' => encrypt('CHUNK0_2FA_SECRET')]);
    $validReq = Request::create('/protected', 'GET');
    $validReq->setUserResolver(fn () => $userWith2fa);
    $validRes = $middleware->handle($validReq, fn () => new Response('2fa-allowed'));
    expect($validRes->getContent())->toBe('2fa-allowed');
});

it('encrypts sensitive screening fields in JSON responses and skips empty bodies', function (): void {
    $middleware = new ScreeningDataEncryptor();
    $request = Request::create('/screening', 'GET');

    $response = $middleware->handle($request, fn () => new Response(json_encode([
        'background_check_status' => 'clear',
        'credit_report_status' => 'excellent',
        'rental_history_status' => 'verified',
        'applicant_name' => 'Jane Doe',
    ], JSON_THROW_ON_ERROR)));

    $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['background_check_status'])->not->toBe('clear')
        ->and(Crypt::decryptString($payload['background_check_status']))->toBe('clear')
        ->and(Crypt::decryptString($payload['credit_report_status']))->toBe('excellent')
        ->and(Crypt::decryptString($payload['rental_history_status']))->toBe('verified')
        ->and($payload['applicant_name'])->toBe('Jane Doe');

    $emptyResponse = $middleware->handle($request, fn () => new Response(''));
    expect($emptyResponse->getContent())->toBe('');
});

it('sets Strict-Transport-Security header only on secure requests in SecurityHeaders', function (): void {
    $middleware = new SecurityHeaders();

    $secureRequest = Request::create('https://billing.test/dashboard', 'GET');
    $secureResponse = $middleware->handle($secureRequest, fn () => new Response('secure'));

    expect($secureResponse->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains; preload')
        ->and($secureResponse->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($secureResponse->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $plainRequest = Request::create('http://billing.test/dashboard', 'GET');
    $plainResponse = $middleware->handle($plainRequest, fn () => new Response('plain'));

    expect($plainResponse->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('returns trusted host patterns from TrustHosts middleware', function (): void {
    config(['app.url' => 'https://billing.test']);

    $middleware = new TrustHosts(app());
    $hosts = $middleware->hosts();

    expect($hosts)->toBeArray()
        ->toHaveCount(1)
        ->and($hosts[0])->toBe('^(.+\.)?billing\.test$');
});

it('covers BulkOperation relationships, status transitions, counters, and progress percentage', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $operation = BulkOperation::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'type' => 'invoice_generation',
        'parameters' => ['batch' => 1],
        'status' => 'pending',
        'total_items' => 0,
        'processed_items' => 0,
        'failed_items' => 0,
    ]);

    expect($operation->user->is($user))->toBeTrue()
        ->and($operation->team->is($team))->toBeTrue()
        ->and($operation->getProgressPercentage())->toBe(0.0);

    $operation->markAsProcessing();
    $operation->update(['total_items' => 3, 'processed_items' => 1]);
    $operation->incrementFailed();

    expect($operation->fresh()->failed_items)->toBe(1)
        ->and($operation->getProgressPercentage())->toBe(33.33);

    $operation->markAsFailed('Storage quota exceeded');
    $operation->refresh();

    expect($operation->status)->toBe('failed')
        ->and($operation->error_message)->toBe('Storage quota exceeded')
        ->and($operation->completed_at)->not->toBeNull();
});

it('resolves Menu parent and children relationships', function (): void {
    $parent = Menu::create([
        'name' => 'Billing',
        'url' => '/billing',
        'order' => 1,
    ]);

    $child = Menu::create([
        'name' => 'Invoices',
        'url' => '/billing/invoices',
        'parent_id' => $parent->id,
        'order' => 2,
    ]);

    expect($parent->parent)->toBeNull()
        ->and($child->parent->is($parent))->toBeTrue()
        ->and($parent->children)->toHaveCount(1)
        ->and($parent->children->first()->is($child))->toBeTrue();
});

it('forces https scheme in AppServiceProvider when running in production', function (): void {
    $prodApp = Mockery::mock(Application::class);
    $prodApp->shouldReceive('isProduction')->once()->andReturn(true);

    URL::shouldReceive('forceScheme')->once()->with('https');

    $provider = new AppServiceProvider($prodApp);
    $method = new ReflectionMethod(AppServiceProvider::class, 'configureUrl');
    $method->invoke($provider);
});

it('registers broadcast routes and channels in BroadcastServiceProvider', function (): void {
    $provider = new BroadcastServiceProvider(app());
    $provider->boot();

    expect(Broadcast::getChannels())->toHaveKey('App.Models.User.{id}');
});

it('boots HorizonServiceProvider and defines the viewHorizon gate', function (): void {
    $provider = new HorizonServiceProvider(app());
    $provider->boot();

    $user = User::factory()->make(['email' => 'unauthorized@example.test']);

    expect(Gate::has('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('registers and boots SocialstreamServiceProvider and TeamServiceProvider', function (): void {
    $socialstreamProvider = new SocialstreamServiceProvider(app());
    $socialstreamProvider->register();
    $socialstreamProvider->boot();

    $teamProvider = new TeamServiceProvider(app());
    $teamProvider->register();
    $teamProvider->boot();

    expect($socialstreamProvider)->toBeInstanceOf(SocialstreamServiceProvider::class)
        ->and($teamProvider)->toBeInstanceOf(TeamServiceProvider::class);
});
