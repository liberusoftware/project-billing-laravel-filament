<?php

declare(strict_types=1);

namespace App\Services\ControlPanels {
    if (! function_exists(__NAMESPACE__.'\\gethostbynamel')) {
        /**
         * Deterministic offline DNS stub for CpanelClient::validateHostname() coverage.
         *
         * @return array<int, string>|false
         */
        function gethostbynamel(string $hostname): array|false
        {
            if ($hostname === 'public-cpanel.example.test') {
                return ['93.184.216.34'];
            }

            return \gethostbynamel($hostname);
        }
    }
}

namespace {
    use App\Models\HostingServer;
    use App\Services\ControlPanels\CpanelClient;
    use GuzzleHttp\Client;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Middleware;
    use GuzzleHttp\Psr7\Response;

    it('resolves and pins a public domain hostname in CpanelClient::validateHostname', function (): void {
        $server = new HostingServer([
            'hostname' => 'public-cpanel.example.test',
            'username' => 'root',
            'api_token' => 'secret-token',
        ]);

        $client = new CpanelClient();
        $client->setServer($server);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"metadata":{"result":1}}'),
        ]));
        $stack->push(Middleware::history($history));
        (new ReflectionProperty($client, 'client'))->setValue($client, new Client(['handler' => $stack]));

        expect($client->unsuspendAccount('alice'))->toBeTrue()
            ->and($history)->toHaveCount(1)
            ->and($history[0]['options']['curl'][CURLOPT_RESOLVE] ?? [])
            ->toBe(['public-cpanel.example.test:2087:93.184.216.34']);
    });
}
