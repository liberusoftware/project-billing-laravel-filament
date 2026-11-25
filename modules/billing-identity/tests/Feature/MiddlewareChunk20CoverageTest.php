<?php

declare(strict_types=1);

use App\Http\Middleware\AssignDefaultTeam;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RoleBasedRedirect;
use App\Http\Middleware\TeamsPermission;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('assigns a default team for authenticated users without a tenant', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(null);

    try {
        $middleware = new AssignDefaultTeam();

        // Guest passes through without setting a tenant
        $guestResponse = $middleware->handle(Request::create('/'), fn () => response('guest'));
        expect($guestResponse->getContent())->toBe('guest')
            ->and(Filament::getTenant())->toBeNull();

        // Authenticated user with no team gets a new personal team created and assigned as tenant
        $userWithoutTeam = User::factory()->create(['current_team_id' => null]);
        Auth::login($userWithoutTeam);
        Filament::setTenant(null);

        $createdResponse = $middleware->handle(Request::create('/'), fn () => response('created'));
        $userWithoutTeam->refresh();

        expect($createdResponse->getContent())->toBe('created')
            ->and($userWithoutTeam->current_team_id)->not->toBeNull()
            ->and(Filament::getTenant()?->getKey())->toBe($userWithoutTeam->current_team_id);

        // Authenticated user who already has a team reuses it
        $existingTeam = Team::factory()->create(['user_id' => $userWithoutTeam->id]);
        $userWithoutTeam->forceFill(['current_team_id' => $existingTeam->id])->save();
        Auth::setUser($userWithoutTeam->fresh());
        Filament::setTenant(null);

        $reusedResponse = $middleware->handle(Request::create('/'), fn () => response('reused'));
        expect($reusedResponse->getContent())->toBe('reused')
            ->and(Filament::getTenant()?->getKey())->toBe($existingTeam->id);
    } finally {
        Filament::setTenant(null);
        Auth::logout();
    }
});

it('resolves redirect path in Authenticate middleware for web and json requests', function () {
    $middleware = new Authenticate(app('auth'));

    $webRequest = Request::create('/dashboard', 'GET');
    try {
        $middleware->handle($webRequest, fn () => response('ok'));
        $this->fail('Expected AuthenticationException for web request.');
    } catch (AuthenticationException $exception) {
        expect($exception->redirectTo($webRequest))->toBe(route('login'));
    }

    $jsonRequest = Request::create('/api/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    try {
        $middleware->handle($jsonRequest, fn () => response('ok'));
        $this->fail('Expected AuthenticationException for JSON request.');
    } catch (AuthenticationException $exception) {
        expect($exception->redirectTo($jsonRequest))->toBeNull();
    }
});

it('enforces roles in CheckRole middleware', function () {
    $middleware = new CheckRole();

    // Guest is rejected with 403
    $guestRequest = Request::create('/admin');
    expect(fn () => $middleware->handle($guestRequest, fn () => response('ok'), 'admin'))
        ->toThrow(HttpException::class);

    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    setPermissionsTeamId($team->id);

    // User without required role is rejected with 403
    $unprivilegedRequest = Request::create('/admin');
    $unprivilegedRequest->setUserResolver(fn () => $user);
    expect(fn () => $middleware->handle($unprivilegedRequest, fn () => response('ok'), 'admin'))
        ->toThrow(HttpException::class);

    // User with required role passes through
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    $authorizedRequest = Request::create('/admin');
    $authorizedRequest->setUserResolver(fn () => $user->fresh());
    $response = $middleware->handle($authorizedRequest, fn () => response('allowed'), 'admin');

    expect($response->getContent())->toBe('allowed');
});

it('passes requests through RedirectIfAuthenticated middleware', function () {
    $middleware = new RedirectIfAuthenticated();
    $response = $middleware->handle(Request::create('/login'), fn () => response('passed'));

    expect($response->getContent())->toBe('passed');
});

it('handles all branches of RoleBasedRedirect middleware', function () {
    $middleware = new RoleBasedRedirect();

    try {
        // 1. Tenant context bypasses redirect
        $tenantResponse = $middleware->handle(Request::create('/tenant/dashboard'), fn () => response('tenant'));
        expect($tenantResponse->getContent())->toBe('tenant');

        // 2. Guest user passes through
        Auth::logout();
        $guestResponse = $middleware->handle(Request::create('/dashboard'), fn () => response('guest'));
        expect($guestResponse->getContent())->toBe('guest');

        // 3. Authenticated user with no roles passes through
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        setPermissionsTeamId($team->id);
        Auth::login($user);

        $noRoleResponse = $middleware->handle(Request::create('/dashboard'), fn () => response('no-role'));
        expect($noRoleResponse->getContent())->toBe('no-role');

        // 4. User with mapped role ('admin') on non-matching path is redirected to /admin
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole($adminRole);
        Auth::setUser($user->fresh());

        $adminRedirect = $middleware->handle(Request::create('/dashboard'), fn () => response('next'));
        expect($adminRedirect)->toBeInstanceOf(RedirectResponse::class)
            ->and($adminRedirect->getTargetUrl())->toEndWith('/admin');

        // 5. User with mapped role ('admin') already on matching path passes through
        $matchingAdminRequest = new class extends Request
        {
            public function decodedPath(): string
            {
                return '/admin';
            }
        };
        $adminPassThrough = $middleware->handle($matchingAdminRequest, fn () => response('on-admin'));
        expect($adminPassThrough->getContent())->toBe('on-admin');

        // 6. User with custom role not in $roleRedirects is redirected to /{role}
        $customUser = User::factory()->create();
        $customTeam = Team::factory()->create(['user_id' => $customUser->id]);
        $customUser->forceFill(['current_team_id' => $customTeam->id])->save();
        setPermissionsTeamId($customTeam->id);
        $customRole = Role::firstOrCreate(['name' => 'custom-portal', 'guard_name' => 'web']);
        $customUser->assignRole($customRole);
        Auth::login($customUser->fresh());

        $customRedirect = $middleware->handle(Request::create('/dashboard'), fn () => response('next'));
        expect($customRedirect)->toBeInstanceOf(RedirectResponse::class)
            ->and($customRedirect->getTargetUrl())->toEndWith('/custom-portal');

        // 7. User with custom role already on matching path passes through
        $matchingCustomRequest = new class extends Request
        {
            public function decodedPath(): string
            {
                return '/custom-portal';
            }
        };
        $customPassThrough = $middleware->handle($matchingCustomRequest, fn () => response('on-custom'));
        expect($customPassThrough->getContent())->toBe('on-custom');
    } finally {
        Auth::logout();
    }
});

it('sets permissions team id in TeamsPermission middleware', function () {
    $middleware = new TeamsPermission();

    try {
        // Guest passes through
        Auth::logout();
        $guestResponse = $middleware->handle(Request::create('/'), fn () => response('guest'));
        expect($guestResponse->getContent())->toBe('guest');

        // Authenticated user with current_team_id sets permissions team id and refreshes user
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        Auth::login($user);

        $response = $middleware->handle(Request::create('/'), fn () => response('scoped'));

        expect($response->getContent())->toBe('scoped')
            ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($team->id);
    } finally {
        Auth::logout();
    }
});
