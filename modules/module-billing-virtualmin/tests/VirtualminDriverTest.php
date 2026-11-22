<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;
use Liberu\Billing\Virtualmin\VirtualminDriver;
use Liberu\Billing\Virtualmin\VirtualminProDriver;
use Liberu\Billing\Virtualmin\VirtualminServiceProvider;

function makeVirtualminDriver(array $responses, array &$history = [], bool $pro = false): VirtualminDriver
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);

    return $pro ? new VirtualminProDriver($client) : new VirtualminDriver($client);
}

function virtualminAttributes(array $overrides = []): array
{
    return array_replace_recursive([
        'server' => [
            'hostname' => 'vm.example.test',
            'username' => 'root',
            'api_token' => 'vm-secret',
        ],
        'account' => [
            'username' => 'vmuser',
            'domain' => 'vmuser.test',
            'email' => 'owner@vmuser.test',
            'password' => 'S3cur3P@ss!',
            'package' => 'default-plan',
        ],
    ], $overrides);
}

it('registers both virtualmin and virtualmin-pro drivers via the service provider', function (): void {
    $registry = new HostingDriverRegistry();

    (new VirtualminServiceProvider(app()))->boot($registry);

    expect($registry->resolve('virtualmin'))->toBeInstanceOf(VirtualminDriver::class);
    expect($registry->resolve('virtualmin-pro'))->toBeInstanceOf(VirtualminProDriver::class);
});

it('provisions, suspends, unsuspends, changes package, manages addons, and terminates accounts', function (): void {
    $history = [];
    $driver = makeVirtualminDriver(array_fill(0, 7, new Response(200, [], 'status=success')), $history);

    expect($driver->key())->toBe('virtualmin');

    expect($driver->provision(virtualminAttributes()))->toBe([
        'provider' => 'virtualmin',
        'external_id' => 'vmuser',
        'status' => 'active',
    ]);

    expect($driver->suspend(virtualminAttributes(['account' => ['reason' => 'Suspended for billing']])))->toBe([
        'provider' => 'virtualmin',
        'external_id' => 'vmuser',
        'status' => 'suspended',
    ]);

    expect($driver->unsuspend(virtualminAttributes()))->toBe([
        'provider' => 'virtualmin',
        'external_id' => 'vmuser',
        'status' => 'active',
    ]);

    expect($driver->changePackage(virtualminAttributes(['account' => ['package' => 'pro-plan']])))->toBe([
        'provider' => 'virtualmin',
        'external_id' => 'vmuser',
        'status' => 'active',
        'package' => 'pro-plan',
    ]);

    expect($driver->addAddon(virtualminAttributes(['account' => ['addon' => 'ssl']])))->toBe([
        'provider' => 'virtualmin',
        'external_id' => 'vmuser',
        'status' => 'active',
        'addon' => 'ssl',
        'enabled' => true,
    ]);

    expect($driver->removeAddon(virtualminAttributes(['account' => ['addon' => 'ssl']])))->toBe([
        'provider' => 'virtualmin',
        'external_id' => 'vmuser',
        'status' => 'active',
        'addon' => 'ssl',
        'enabled' => false,
    ]);

    expect($driver->terminate(virtualminAttributes()))->toBe([
        'provider' => 'virtualmin',
        'external_id' => 'vmuser',
        'status' => 'terminated',
    ]);

    expect($history)->toHaveCount(7);
    expect((string) $history[0]['request']->getUri())->toBe('https://vm.example.test:10000/virtual-server/remote.cgi');
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('root:vm-secret'));
    expect((string) $history[4]['request']->getBody())->toContain('enable-feature=ssl');
    expect((string) $history[5]['request']->getBody())->toContain('disable-feature=ssl');

    $proDriver = makeVirtualminDriver([new Response(200, [], 'status=success')], pro: true);
    expect($proDriver->key())->toBe('virtualmin-pro');
    expect($proDriver->provision(virtualminAttributes()))->toBe([
        'provider' => 'virtualmin-pro',
        'external_id' => 'vmuser',
        'status' => 'active',
    ]);
});

it('validates required virtualmin fields and handles rejections', function (): void {
    $driver = makeVirtualminDriver([new Response(200, [], 'status=error&error=Domain+exists')]);

    expect(fn () => $driver->changePackage(virtualminAttributes(['account' => ['package' => '', 'new_package' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A new Virtualmin plan is required.');

    expect(fn () => $driver->addAddon(virtualminAttributes(['account' => ['addon' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A Virtualmin addon is required.');

    expect(fn () => $driver->provision(virtualminAttributes(['server' => ['username' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A Virtualmin username is required.');

    expect(fn () => $driver->provision(virtualminAttributes()))
        ->toThrow(RuntimeException::class, 'Domain exists');
});
