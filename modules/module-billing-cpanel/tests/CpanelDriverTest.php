<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Liberu\Billing\Cpanel\CpanelDriver;
use Liberu\Billing\Cpanel\CpanelServiceProvider;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;

function makeCpanelDriver(array $responses, array &$history = []): CpanelDriver
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new CpanelDriver(new Client(['handler' => $stack]));
}

function cpanelAttributes(array $overrides = []): array
{
    return array_replace_recursive([
        'server' => [
            'hostname' => 'whm.example.test',
            'username' => 'root',
            'api_token' => 'secret-token',
            'ip_address' => '192.0.2.10',
        ],
        'account' => [
            'username' => 'acmeuser',
            'domain' => 'acme.test',
            'email' => 'admin@acme.test',
            'password' => 'S3cur3P@ss!',
            'package' => 'starter',
        ],
    ], $overrides);
}

it('registers the cpanel driver via the service provider', function (): void {
    $registry = new HostingDriverRegistry();

    (new CpanelServiceProvider(app()))->boot($registry);

    expect($registry->resolve('cpanel'))->toBeInstanceOf(CpanelDriver::class);
});

it('provisions, suspends, unsuspends, changes package, manages addons, and terminates accounts', function (): void {
    $history = [];
    $okJson = json_encode(['metadata' => ['result' => 1, 'reason' => 'OK']], JSON_THROW_ON_ERROR);

    $driver = makeCpanelDriver(array_fill(0, 7, new Response(200, [], $okJson)), $history);

    expect($driver->key())->toBe('cpanel');

    $provision = $driver->provision(cpanelAttributes());
    expect($provision)->toBe([
        'provider' => 'cpanel',
        'external_id' => 'acmeuser',
        'status' => 'active',
    ]);

    $suspend = $driver->suspend(cpanelAttributes(['account' => ['reason' => 'Overdue invoice']]));
    expect($suspend)->toBe([
        'provider' => 'cpanel',
        'external_id' => 'acmeuser',
        'status' => 'suspended',
    ]);

    $unsuspend = $driver->unsuspend(cpanelAttributes());
    expect($unsuspend)->toBe([
        'provider' => 'cpanel',
        'external_id' => 'acmeuser',
        'status' => 'active',
    ]);

    $changePackage = $driver->changePackage(cpanelAttributes(['account' => ['package' => 'business']]));
    expect($changePackage)->toBe([
        'provider' => 'cpanel',
        'external_id' => 'acmeuser',
        'status' => 'active',
        'package' => 'business',
    ]);

    $addAddon = $driver->addAddon(cpanelAttributes(['account' => ['addon' => 'ssl']]));
    expect($addAddon)->toBe([
        'provider' => 'cpanel',
        'external_id' => 'acmeuser',
        'status' => 'active',
        'addon' => 'ssl',
        'enabled' => true,
    ]);

    $removeAddon = $driver->removeAddon(cpanelAttributes(['account' => ['addon' => 'ssl']]));
    expect($removeAddon)->toBe([
        'provider' => 'cpanel',
        'external_id' => 'acmeuser',
        'status' => 'active',
        'addon' => 'ssl',
        'enabled' => false,
    ]);

    $terminate = $driver->terminate(cpanelAttributes());
    expect($terminate)->toBe([
        'provider' => 'cpanel',
        'external_id' => 'acmeuser',
        'status' => 'terminated',
    ]);

    expect($history)->toHaveCount(7);
    expect((string) $history[0]['request']->getUri())->toContain('https://whm.example.test:2087/json-api/createacct');
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('whm root:secret-token');
    expect((string) $history[1]['request']->getUri())->toContain('/json-api/suspendacct');
    expect((string) $history[2]['request']->getUri())->toContain('/json-api/unsuspendacct');
    expect((string) $history[3]['request']->getUri())->toContain('/json-api/changepackage');
    expect((string) $history[4]['request']->getUri())->toContain('/json-api/modifyacct');
    expect((string) $history[4]['request']->getUri())->toContain('FEATURE-SSL=1');
    expect((string) $history[5]['request']->getUri())->toContain('FEATURE-SSL=0');
    expect((string) $history[6]['request']->getUri())->toContain('/json-api/removeacct');
});

it('validates required cpanel package and addon fields', function (): void {
    $driver = makeCpanelDriver([]);

    expect(fn () => $driver->provision(cpanelAttributes(['account' => ['package' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A cPanel package is required.');

    expect(fn () => $driver->changePackage(cpanelAttributes(['account' => ['package' => '', 'new_package' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A new cPanel package is required.');

    expect(fn () => $driver->addAddon(cpanelAttributes(['account' => ['addon' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A cPanel addon is required.');
});

it('throws when cpanel rejects an operation', function (): void {
    $errorJson = json_encode(['metadata' => ['result' => 0, 'reason' => 'Account already exists']], JSON_THROW_ON_ERROR);
    $driver = makeCpanelDriver([new Response(200, [], $errorJson)]);

    expect(fn () => $driver->provision(cpanelAttributes()))
        ->toThrow(RuntimeException::class, 'Account already exists');
});
