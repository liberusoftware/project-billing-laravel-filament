<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Liberu\Billing\DirectAdmin\DirectAdminDriver;
use Liberu\Billing\DirectAdmin\DirectAdminServiceProvider;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;

function makeDirectAdminDriver(array $responses, array &$history = []): DirectAdminDriver
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new DirectAdminDriver(new Client(['handler' => $stack]));
}

function directAdminAttributes(array $overrides = []): array
{
    return array_replace_recursive([
        'server' => [
            'hostname' => 'da.example.test',
            'username' => 'admin',
            'api_token' => 'da-secret-key',
            'ip_address' => '192.0.2.20',
        ],
        'account' => [
            'username' => 'dauser',
            'domain' => 'dauser.test',
            'email' => 'owner@dauser.test',
            'password' => 'S3cur3P@ss!',
            'package' => 'gold',
        ],
    ], $overrides);
}

it('registers the directadmin driver via the service provider', function (): void {
    $registry = new HostingDriverRegistry();

    (new DirectAdminServiceProvider(app()))->boot($registry);

    expect($registry->resolve('directadmin'))->toBeInstanceOf(DirectAdminDriver::class);
});

it('provisions, suspends, unsuspends, changes package, manages addons, and terminates accounts', function (): void {
    $history = [];
    $driver = makeDirectAdminDriver(array_fill(0, 7, new Response(200, [], 'error=0&text=OK')), $history);

    expect($driver->key())->toBe('directadmin');

    expect($driver->provision(directAdminAttributes()))->toBe([
        'provider' => 'directadmin',
        'external_id' => 'dauser',
        'status' => 'active',
    ]);

    expect($driver->suspend(directAdminAttributes(['account' => ['reason' => 'Non-payment']])))->toBe([
        'provider' => 'directadmin',
        'external_id' => 'dauser',
        'status' => 'suspended',
    ]);

    expect($driver->unsuspend(directAdminAttributes()))->toBe([
        'provider' => 'directadmin',
        'external_id' => 'dauser',
        'status' => 'active',
    ]);

    expect($driver->changePackage(directAdminAttributes(['account' => ['package' => 'platinum']])))->toBe([
        'provider' => 'directadmin',
        'external_id' => 'dauser',
        'status' => 'active',
        'package' => 'platinum',
    ]);

    expect($driver->addAddon(directAdminAttributes(['account' => ['addon' => 'dedicated_ip']])))->toBe([
        'provider' => 'directadmin',
        'external_id' => 'dauser',
        'status' => 'active',
        'addon' => 'dedicated_ip',
        'enabled' => true,
    ]);

    expect($driver->removeAddon(directAdminAttributes(['account' => ['addon' => 'dedicated_ip']])))->toBe([
        'provider' => 'directadmin',
        'external_id' => 'dauser',
        'status' => 'active',
        'addon' => 'dedicated_ip',
        'enabled' => false,
    ]);

    expect($driver->terminate(directAdminAttributes()))->toBe([
        'provider' => 'directadmin',
        'external_id' => 'dauser',
        'status' => 'terminated',
    ]);

    expect($history)->toHaveCount(7);
    expect((string) $history[0]['request']->getUri())->toBe('https://da.example.test:2222/CMD_API_ACCOUNT_USER');
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('admin:da-secret-key'));
    expect((string) $history[1]['request']->getUri())->toContain('/CMD_API_SELECT_USERS');
    expect((string) $history[3]['request']->getUri())->toContain('/CMD_API_MODIFY_USER');
    expect((string) $history[4]['request']->getBody())->toContain('add=dedicated_ip');
    expect((string) $history[5]['request']->getBody())->toContain('remove=dedicated_ip');
});

it('validates required directadmin fields and handles rejection errors', function (): void {
    $driver = makeDirectAdminDriver([new Response(200, [], 'error=1&text=Permission+denied')]);

    expect(fn () => $driver->changePackage(directAdminAttributes(['account' => ['package' => '', 'new_package' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A new DirectAdmin package is required.');

    expect(fn () => $driver->addAddon(directAdminAttributes(['account' => ['addon' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A DirectAdmin addon is required.');

    expect(fn () => $driver->provision(directAdminAttributes(['server' => ['username' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A DirectAdmin username is required.');

    expect(fn () => $driver->provision(directAdminAttributes()))
        ->toThrow(RuntimeException::class, 'Permission denied');
});
