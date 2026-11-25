<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;
use Liberu\Billing\Plesk\PleskDriver;
use Liberu\Billing\Plesk\PleskServiceProvider;

function makePleskDriver(array $responses, array &$history = []): PleskDriver
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new PleskDriver(new Client(['handler' => $stack]));
}

function pleskAttributes(array $overrides = []): array
{
    return array_replace_recursive([
        'server' => [
            'hostname' => 'plesk.example.test',
            'api_token' => 'plesk-api-key',
            'ip_address' => '192.0.2.30',
        ],
        'account' => [
            'username' => 'pleskuser',
            'domain' => 'pleskuser.test',
            'password' => 'S3cur3P@ss!',
            'package' => 'unlimited',
        ],
    ], $overrides);
}

it('registers the plesk driver via the service provider', function (): void {
    $registry = new HostingDriverRegistry();

    (new PleskServiceProvider(app()))->boot($registry);

    expect($registry->resolve('plesk'))->toBeInstanceOf(PleskDriver::class);
});

it('provisions, suspends, unsuspends, changes package, manages addons, and terminates accounts', function (): void {
    $history = [];
    $okXml = '<packet version="1.6.9.1"><webspace><add><result><status>ok</status><errcode>0</errcode></result></add></webspace></packet>';
    $driver = makePleskDriver(array_fill(0, 8, new Response(200, [], $okXml)), $history);

    expect($driver->key())->toBe('plesk');

    expect($driver->provision(pleskAttributes()))->toBe([
        'provider' => 'plesk',
        'external_id' => 'pleskuser',
        'status' => 'active',
    ]);

    // Also cover scalar-list XML serialization branch in append().
    expect($driver->provision(pleskAttributes(['server' => ['ip_address' => ['192.0.2.30', '192.0.2.31']]])))->toBe([
        'provider' => 'plesk',
        'external_id' => 'pleskuser',
        'status' => 'active',
    ]);

    expect($driver->suspend(pleskAttributes()))->toBe([
        'provider' => 'plesk',
        'external_id' => 'pleskuser',
        'status' => 'suspended',
    ]);

    expect($driver->unsuspend(pleskAttributes()))->toBe([
        'provider' => 'plesk',
        'external_id' => 'pleskuser',
        'status' => 'active',
    ]);

    expect($driver->changePackage(pleskAttributes(['account' => ['package' => 'agency']])))->toBe([
        'provider' => 'plesk',
        'external_id' => 'pleskuser',
        'status' => 'active',
        'package' => 'agency',
    ]);

    expect($driver->addAddon(pleskAttributes(['account' => ['addon' => 'wp-toolkit']])))->toBe([
        'provider' => 'plesk',
        'external_id' => 'pleskuser',
        'status' => 'active',
        'addon' => 'wp-toolkit',
        'enabled' => true,
    ]);

    expect($driver->removeAddon(pleskAttributes(['account' => ['addon' => 'wp-toolkit']])))->toBe([
        'provider' => 'plesk',
        'external_id' => 'pleskuser',
        'status' => 'active',
        'addon' => 'wp-toolkit',
        'enabled' => false,
    ]);

    expect($driver->terminate(pleskAttributes()))->toBe([
        'provider' => 'plesk',
        'external_id' => 'pleskuser',
        'status' => 'terminated',
    ]);

    expect($history)->toHaveCount(8);
    expect((string) $history[0]['request']->getUri())->toBe('https://plesk.example.test:8443/api/v2/cli/server/');
    expect($history[0]['request']->getHeaderLine('KEY'))->toBe('plesk-api-key');
    expect((string) $history[0]['request']->getBody())->toContain('<webspace.add>');
    expect((string) $history[1]['request']->getBody())->toContain('<ip_address>192.0.2.31</ip_address>');
    expect((string) $history[5]['request']->getBody())->toContain('<site-addon.add>');
    expect((string) $history[6]['request']->getBody())->toContain('<site-addon.del>');
    expect((string) $history[7]['request']->getBody())->toContain('<webspace.del>');
});

it('validates required plesk fields and handles status and errcode rejections', function (): void {
    $statusErrorXml = '<packet><result><status>error</status><errtext>Invalid plan</errtext></result></packet>';
    $errcodeXml = '<packet><result><status>ok</status><errcode>1006</errcode><errtext>Permission denied</errtext></result></packet>';

    $driver = makePleskDriver([
        new Response(200, [], $statusErrorXml),
        new Response(200, [], $errcodeXml),
    ]);

    expect(fn () => $driver->changePackage(pleskAttributes(['account' => ['package' => '', 'new_package' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A new Plesk service plan is required.');

    expect(fn () => $driver->addAddon(pleskAttributes(['account' => ['addon' => '']])))
        ->toThrow(InvalidArgumentException::class, 'A Plesk addon is required.');

    expect(fn () => $driver->provision(pleskAttributes()))
        ->toThrow(RuntimeException::class, 'Invalid plan');

    expect(fn () => $driver->terminate(pleskAttributes()))
        ->toThrow(RuntimeException::class, 'Permission denied');
});
