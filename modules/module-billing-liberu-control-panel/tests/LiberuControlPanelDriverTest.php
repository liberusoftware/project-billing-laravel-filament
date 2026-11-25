<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;
use Liberu\Billing\LiberuControlPanel\LiberuControlPanelDriver;
use Liberu\Billing\LiberuControlPanel\LiberuControlPanelServiceProvider;

function makeLiberuControlPanelDriver(array $responses, array &$history = []): LiberuControlPanelDriver
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new LiberuControlPanelDriver(new Client(['handler' => $stack]));
}

function liberuControlPanelAttributes(array $overrides = []): array
{
    return array_replace_recursive([
        'server' => [
            'api_url' => 'https://panel.liberu.test',
            'api_token' => 'liberu-token',
        ],
        'account' => [
            'username' => 'liberu_user',
            'domain' => 'liberu-site.test',
            'email' => 'admin@liberu-site.test',
            'password' => 'S3cur3P@ss!',
            'package' => 'pro',
        ],
    ], $overrides);
}

it('registers the liberu control panel driver via the service provider', function (): void {
    $registry = new HostingDriverRegistry();

    (new LiberuControlPanelServiceProvider(app()))->boot($registry);

    expect($registry->resolve('liberu'))->toBeInstanceOf(LiberuControlPanelDriver::class);
});

it('provisions, suspends, unsuspends, changes package, manages addons, and terminates accounts', function (): void {
    $history = [];
    $okJson = json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
    $driver = makeLiberuControlPanelDriver(array_fill(0, 7, new Response(200, [], $okJson)), $history);

    expect($driver->key())->toBe('liberu');

    expect($driver->provision(liberuControlPanelAttributes()))->toBe([
        'provider' => 'liberu',
        'external_id' => 'liberu_user',
        'status' => 'active',
    ]);

    expect($driver->suspend(liberuControlPanelAttributes(['account' => ['reason' => 'Abuse']])))->toBe([
        'provider' => 'liberu',
        'external_id' => 'liberu_user',
        'status' => 'suspended',
    ]);

    expect($driver->unsuspend(liberuControlPanelAttributes()))->toBe([
        'provider' => 'liberu',
        'external_id' => 'liberu_user',
        'status' => 'active',
    ]);

    expect($driver->changePackage(liberuControlPanelAttributes(['account' => ['package' => 'enterprise']])))->toBe([
        'provider' => 'liberu',
        'external_id' => 'liberu_user',
        'status' => 'active',
        'package' => 'enterprise',
    ]);

    expect($driver->addAddon(liberuControlPanelAttributes(['account' => ['addon' => 'daily-backups']])))->toBe([
        'provider' => 'liberu',
        'external_id' => 'liberu_user',
        'status' => 'active',
        'addon' => 'daily-backups',
        'enabled' => true,
    ]);

    expect($driver->removeAddon(liberuControlPanelAttributes(['account' => ['addon' => 'daily-backups']])))->toBe([
        'provider' => 'liberu',
        'external_id' => 'liberu_user',
        'status' => 'active',
        'addon' => 'daily-backups',
        'enabled' => false,
    ]);

    expect($driver->terminate(liberuControlPanelAttributes()))->toBe([
        'provider' => 'liberu',
        'external_id' => 'liberu_user',
        'status' => 'terminated',
    ]);

    expect($history)->toHaveCount(7);
    expect($history[0]['request']->getMethod())->toBe('POST');
    expect((string) $history[0]['request']->getUri())->toBe('https://panel.liberu.test/api/hosting/accounts');
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer liberu-token');
    expect((string) $history[1]['request']->getUri())->toBe('https://panel.liberu.test/api/hosting/accounts/liberu_user/suspend');
    expect((string) $history[2]['request']->getUri())->toBe('https://panel.liberu.test/api/hosting/accounts/liberu_user/unsuspend');
    expect($history[3]['request']->getMethod())->toBe('PUT');
    expect((string) $history[3]['request']->getUri())->toBe('https://panel.liberu.test/api/hosting/accounts/liberu_user/package');
    expect($history[4]['request']->getMethod())->toBe('POST');
    expect((string) $history[4]['request']->getUri())->toBe('https://panel.liberu.test/api/hosting/accounts/liberu_user/addons');
    expect($history[5]['request']->getMethod())->toBe('DELETE');
    expect((string) $history[5]['request']->getUri())->toBe('https://panel.liberu.test/api/hosting/accounts/liberu_user/addons/daily-backups');
    expect($history[6]['request']->getMethod())->toBe('DELETE');
    expect((string) $history[6]['request']->getUri())->toBe('https://panel.liberu.test/api/hosting/accounts/liberu_user');
});

it('validates required liberu control panel package and addon fields', function (): void {
    $driver = makeLiberuControlPanelDriver([]);

    expect(fn () => $driver->changePackage(liberuControlPanelAttributes(['account' => ['package' => '', 'new_package' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A new Liberu Control Panel package is required.');

    expect(fn () => $driver->addAddon(liberuControlPanelAttributes(['account' => ['addon' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A Liberu Control Panel addon is required.');
});
