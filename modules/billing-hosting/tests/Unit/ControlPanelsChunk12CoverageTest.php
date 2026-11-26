<?php

declare(strict_types=1);

use App\Models\HostingServer;
use App\Services\ControlPanels\CpanelClient;
use App\Services\ControlPanels\DirectAdminClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$injectGuzzle = static function (object $client, array $responses, array &$history): void {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));
};

it('executes CpanelClient lifecycle and addon methods with expected endpoints and query parameters', function () use ($injectGuzzle): void {
    $server = new HostingServer([
        'hostname' => '203.0.113.10',
        'username' => 'root',
        'api_token' => 'secret-token',
    ]);

    $client = new CpanelClient();
    $client->setServer($server);

    $history = [];
    $ok = '{"metadata":{"result":1}}';
    $injectGuzzle($client, [
        new Response(200, [], $ok),
        new Response(200, [], $ok),
        new Response(200, [], $ok),
        new Response(200, [], $ok),
        new Response(200, [], $ok),
    ], $history);

    expect($client->unsuspendAccount('alice'))->toBeTrue()
        ->and($client->changePackage('alice', 'pro-plan'))->toBeTrue()
        ->and($client->terminateAccount('alice'))->toBeTrue()
        ->and($client->addAddon('alice', 'ssl'))->toBeTrue()
        ->and($client->removeAddon('alice', 'ssl'))->toBeTrue()
        ->and($history)->toHaveCount(5);

    expect($history[0]['request']->getUri()->getPath())->toBe('/json-api/unsuspendacct')
        ->and($history[0]['request']->getUri()->getQuery())->toContain('user=alice')
        ->and($history[1]['request']->getUri()->getPath())->toBe('/json-api/changepackage')
        ->and($history[1]['request']->getUri()->getQuery())->toContain('pkg=pro-plan')
        ->and($history[2]['request']->getUri()->getPath())->toBe('/json-api/removeacct')
        ->and($history[2]['request']->getUri()->getQuery())->toContain('keepdns=0')
        ->and($history[3]['request']->getUri()->getPath())->toBe('/json-api/modifyacct')
        ->and($history[3]['request']->getUri()->getQuery())->toContain('FEATURE-SSL=1')
        ->and($history[4]['request']->getUri()->getPath())->toBe('/json-api/modifyacct')
        ->and($history[4]['request']->getUri()->getQuery())->toContain('FEATURE-SSL=0');
});

it('throws when CpanelClient::createSsoSession is called without a configured server', function (): void {
    $client = new CpanelClient();

    expect(fn () => $client->createSsoSession('alice'))
        ->toThrow(Exception::class, 'Server not configured');
});

it('returns null from CpanelClient::createSsoSession on transport or JSON errors', function () use ($injectGuzzle): void {
    $server = new HostingServer([
        'hostname' => '203.0.113.10',
        'username' => 'root',
        'api_token' => 'secret-token',
    ]);

    $client = new CpanelClient();
    $client->setServer($server);

    $history = [];
    $injectGuzzle($client, [
        new ConnectException('connection refused', new Request('GET', 'https://203.0.113.10:2087/json-api/create_user_session')),
        new Response(200, [], 'not-valid-json'),
    ], $history);

    expect($client->createSsoSession('alice'))->toBeNull()
        ->and($client->createSsoSession('alice'))->toBeNull();
});

it('returns false from CpanelClient API calls on transport or JSON errors', function () use ($injectGuzzle): void {
    $server = new HostingServer([
        'hostname' => '203.0.113.10',
        'username' => 'root',
        'api_token' => 'secret-token',
    ]);

    $client = new CpanelClient();
    $client->setServer($server);

    $history = [];
    $injectGuzzle($client, [
        new ConnectException('connection refused', new Request('GET', 'https://203.0.113.10:2087/json-api/unsuspendacct')),
        new Response(200, [], 'not-valid-json'),
    ], $history);

    expect($client->unsuspendAccount('alice'))->toBeFalse()
        ->and($client->unsuspendAccount('alice'))->toBeFalse();
});

it('validates CpanelClient hostnames against private IPs, malformed hosts, and unresolvable domains', function (string $hostname, string $expectedMessage): void {
    $client = new CpanelClient();
    $client->setServer(new HostingServer([
        'hostname' => $hostname,
        'username' => 'root',
        'api_token' => 'secret-token',
    ]));

    expect(fn () => $client->unsuspendAccount('alice'))
        ->toThrow(Exception::class, $expectedMessage);
})->with([
    'private ipv4' => ['127.0.0.1', 'Private or reserved IP addresses are not allowed as cPanel hostnames'],
    'rfc1918 ipv4' => ['10.0.0.1', 'Private or reserved IP addresses are not allowed as cPanel hostnames'],
    'invalid hostname' => ['invalid host!', 'Invalid cPanel hostname'],
    'unresolvable domain' => ['nonexistent-chunk12.invalid.', 'cPanel hostname could not be resolved'],
]);

it('executes DirectAdminClient lifecycle and addon methods with expected endpoints and form payloads', function () use ($injectGuzzle): void {
    $server = new HostingServer([
        'hostname' => '203.0.113.20',
        'username' => 'admin',
        'api_token' => 'login-key',
        'ip_address' => '203.0.113.20',
    ]);

    $client = new DirectAdminClient();
    $client->setServer($server);

    $history = [];
    $injectGuzzle($client, [
        new Response(200, [], 'error=0'),
        new Response(200, [], 'error=0'),
        new Response(200, [], 'error=0'),
        new Response(200, [], 'error=0'),
        new Response(200, [], 'error=0'),
    ], $history);

    expect($client->unsuspendAccount('alice'))->toBeTrue()
        ->and($client->changePackage('alice', 'pro-plan'))->toBeTrue()
        ->and($client->terminateAccount('alice'))->toBeTrue()
        ->and($client->addAddon('alice', 'ssl'))->toBeTrue()
        ->and($client->removeAddon('alice', 'ssl'))->toBeTrue()
        ->and($history)->toHaveCount(5);

    expect($history[0]['request']->getUri()->getPath())->toBe('/CMD_API_SELECT_USERS')
        ->and((string) $history[0]['request']->getBody())->toContain('action=unsuspend')
        ->and($history[1]['request']->getUri()->getPath())->toBe('/CMD_API_MODIFY_USER')
        ->and((string) $history[1]['request']->getBody())->toContain('package=pro-plan')
        ->and($history[2]['request']->getUri()->getPath())->toBe('/CMD_API_SELECT_USERS')
        ->and((string) $history[2]['request']->getBody())->toContain('delete=yes')
        ->and($history[3]['request']->getUri()->getPath())->toBe('/CMD_API_MODIFY_USER')
        ->and((string) $history[3]['request']->getBody())->toContain('add=ssl')
        ->and($history[4]['request']->getUri()->getPath())->toBe('/CMD_API_MODIFY_USER')
        ->and((string) $history[4]['request']->getBody())->toContain('remove=ssl');
});

it('returns false from DirectAdminClient API calls on transport errors', function () use ($injectGuzzle): void {
    $server = new HostingServer([
        'hostname' => '203.0.113.20',
        'username' => 'admin',
        'api_token' => 'login-key',
    ]);

    $client = new DirectAdminClient();
    $client->setServer($server);

    $history = [];
    $injectGuzzle($client, [
        new ConnectException('connection refused', new Request('POST', 'https://203.0.113.20:2222/CMD_API_SELECT_USERS')),
    ], $history);

    expect($client->unsuspendAccount('alice'))->toBeFalse();
});
