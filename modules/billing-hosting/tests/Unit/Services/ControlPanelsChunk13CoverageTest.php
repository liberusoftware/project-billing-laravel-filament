<?php

declare(strict_types=1);

use App\Models\HostingServer;
use App\Services\ControlPanels\PleskClient;
use App\Services\ControlPanels\SoftaculousClient;
use App\Services\ControlPanels\VirtualminClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;

it('returns false from Plesk createAccount when customer creation fails without creating a webspace', function (): void {
    $client = new PleskClient();
    $client->setServer(new HostingServer([
        'hostname' => 'plesk.example.com',
        'username' => 'admin',
        'api_token' => 'secret-token',
        'ip_address' => '192.0.2.10',
    ]));

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '<packet><customer><add><result><status>error</status></result></add></customer></packet>'),
    ]));
    $stack->push(Middleware::history($history));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->createAccount('alice', 'example.com', 'starter'))->toBeFalse()
        ->and($history)->toHaveCount(1);

    $xml = simplexml_load_string((string) $history[0]['request']->getBody());
    expect($xml)->not->toBeFalse()
        ->and((string) $xml->customer->add->gen_info->login)->toBe('alice');
});

it('unsuspends a Plesk account by setting customer status to 0', function (): void {
    $client = new PleskClient();
    $client->setServer(new HostingServer([
        'hostname' => 'plesk.example.com',
        'api_token' => 'secret-token',
    ]));

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '<packet><customer><set><result><status>ok</status></result></set></customer></packet>'),
    ]));
    $stack->push(Middleware::history($history));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->unsuspendAccount('alice'))->toBeTrue()
        ->and($history)->toHaveCount(1);

    $xml = simplexml_load_string((string) $history[0]['request']->getBody());
    expect($xml)->not->toBeFalse()
        ->and((string) $xml->customer->set->filter->login)->toBe('alice')
        ->and((string) $xml->customer->set->gen_info->status)->toBe('0');
});

it('terminates a Plesk account by deleting webspaces for the owner login', function (): void {
    $client = new PleskClient();
    $client->setServer(new HostingServer([
        'hostname' => 'plesk.example.com',
        'api_token' => 'secret-token',
    ]));

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '<packet><webspace><del><result><status>ok</status></result></del></webspace></packet>'),
    ]));
    $stack->push(Middleware::history($history));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->terminateAccount('alice'))->toBeTrue()
        ->and($history)->toHaveCount(1);

    $xml = simplexml_load_string((string) $history[0]['request']->getBody());
    expect($xml)->not->toBeFalse()
        ->and((string) $xml->webspace->del->filter->{'owner-login'})->toBe('alice');
});

it('returns false from Plesk changePackage when plan lookup fails', function (): void {
    $client = new PleskClient();
    $client->setServer(new HostingServer([
        'hostname' => 'plesk.example.com',
        'api_token' => 'secret-token',
    ]));

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '<packet><service-plan><get><result><status>error</status></result></get></service-plan></packet>'),
    ]));
    $stack->push(Middleware::history($history));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->changePackage('alice', 'missing-plan'))->toBeFalse()
        ->and($history)->toHaveCount(1);
});

it('returns false from Plesk changePackage when plan lookup does not yield a single non-empty guid', function (string $responseBody): void {
    $client = new PleskClient();
    $client->setServer(new HostingServer([
        'hostname' => 'plesk.example.com',
        'api_token' => 'secret-token',
    ]));

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], $responseBody),
    ]));
    $stack->push(Middleware::history($history));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->changePackage('alice', 'ambiguous-plan'))->toBeFalse()
        ->and($history)->toHaveCount(1);
})->with([
    'missing guid' => ['<packet><service-plan><get><result><status>ok</status></result></get></service-plan></packet>'],
    'empty guid' => ['<packet><service-plan><get><result><status>ok</status><guid></guid></result></get></service-plan></packet>'],
    'multiple guids' => ['<packet><service-plan><get><result><status>ok</status><guid>guid-1</guid></result><result><status>ok</status><guid>guid-2</guid></result></get></service-plan></packet>'],
]);

it('logs and returns false when a Plesk API call throws a GuzzleException', function (): void {
    Log::spy();

    $client = new PleskClient();
    $client->setServer(new HostingServer([
        'hostname' => 'plesk.example.com',
        'api_token' => 'secret-token',
    ]));

    $stack = HandlerStack::create(new MockHandler([
        new ConnectException('Connection refused', new Request('POST', 'https://plesk.example.com:8443/enterprise/control/agent.php')),
    ]));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->suspendAccount('alice'))->toBeFalse();

    Log::shouldHaveReceived('error')->once()->with('Plesk API call error', [
        'server' => 'plesk.example.com',
        'error' => ConnectException::class,
    ]);
});

it('logs and returns false when Softaculous encounters a transport or JSON error', function (mixed $mockResponse, string $expectedErrorClass): void {
    Log::spy();
    config()->set('services.softaculous.api_url', 'https://softaculous.example.com');
    config()->set('services.softaculous.api_token', 'softaculous-token');

    $client = new SoftaculousClient();
    $stack = HandlerStack::create(new MockHandler([$mockResponse]));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->installScript('example.com', 26, ['site_name' => 'Demo']))->toBeFalse();

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($expectedErrorClass): bool {
        return $message === 'Softaculous API call error'
            && $context['endpoint'] === '/install'
            && $context['error'] === $expectedErrorClass;
    });
})->with([
    'guzzle exception' => [
        new ConnectException('Connection timed out', new Request('POST', 'https://softaculous.example.com/install')),
        ConnectException::class,
    ],
    'invalid json' => [
        new Response(200, [], 'not-valid-json'),
        JsonException::class,
    ],
]);

it('executes Virtualmin lifecycle and addon operations with expected parameters', function (string $method, array $args, array $expectedParams): void {
    $client = new VirtualminClient();
    $client->setServer(new HostingServer([
        'hostname' => 'virtualmin.example.com',
        'username' => 'root',
        'api_token' => 'vm-secret',
    ]));

    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"status":"success"}'),
    ]));
    $stack->push(Middleware::history($history));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->{$method}(...$args))->toBeTrue()
        ->and($history)->toHaveCount(1);

    parse_str((string) $history[0]['request']->getBody(), $params);
    foreach ($expectedParams as $key => $value) {
        expect($params[$key] ?? null)->toBe($value);
    }
    expect($params['json'] ?? null)->toBe('1');
})->with([
    'unsuspendAccount' => ['unsuspendAccount', ['alice'], ['program' => 'enable-domain', 'user' => 'alice']],
    'changePackage' => ['changePackage', ['alice', 'pro-plan'], ['program' => 'modify-domain', 'user' => 'alice', 'apply-plan' => 'pro-plan']],
    'terminateAccount' => ['terminateAccount', ['alice'], ['program' => 'delete-domain', 'user' => 'alice']],
    'addAddon' => ['addAddon', ['alice', 'ssl'], ['program' => 'modify-domain', 'user' => 'alice', 'enable-feature' => 'ssl']],
    'removeAddon' => ['removeAddon', ['alice', 'ssl'], ['program' => 'modify-domain', 'user' => 'alice', 'disable-feature' => 'ssl']],
]);

it('logs and returns false when Virtualmin encounters a transport or JSON error', function (mixed $mockResponse, string $expectedErrorClass): void {
    Log::spy();

    $client = new VirtualminClient();
    $client->setServer(new HostingServer([
        'hostname' => 'virtualmin.example.com',
        'username' => 'root',
        'api_token' => 'vm-secret',
    ]));

    $stack = HandlerStack::create(new MockHandler([$mockResponse]));
    (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

    expect($client->unsuspendAccount('alice'))->toBeFalse();

    Log::shouldHaveReceived('error')->once()->with('Virtualmin API call error', [
        'program' => 'enable-domain',
        'server' => 'virtualmin.example.com',
        'error' => $expectedErrorClass,
    ]);
})->with([
    'guzzle exception' => [
        new ConnectException('Host unreachable', new Request('POST', 'https://virtualmin.example.com:10000/virtual-server/remote.cgi')),
        ConnectException::class,
    ],
    'invalid json' => [
        new Response(200, [], '<html>error</html>'),
        JsonException::class,
    ],
]);
