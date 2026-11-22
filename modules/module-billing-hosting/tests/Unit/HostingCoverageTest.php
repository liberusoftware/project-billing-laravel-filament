<?php

declare(strict_types=1);

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Hosting\Actions\CreateHostingAccount;
use Liberu\Billing\Hosting\Actions\CreateHostingCapability;
use Liberu\Billing\Hosting\Actions\CreateHostingServer;
use Liberu\Billing\Hosting\Actions\PerformHostingOperation;
use Liberu\Billing\Hosting\Actions\TransitionHostingCapability;
use Liberu\Billing\Hosting\Contracts\ControlPanelDriver;
use Liberu\Billing\Hosting\Contracts\HostingDriver;
use Liberu\Billing\Hosting\Events\HostingServerCreated;
use Liberu\Billing\Hosting\Models\HostingServer;
use Liberu\Billing\Hosting\Policies\HostingAccountPolicy;
use Liberu\Billing\Hosting\Policies\HostingCapabilityPolicy;
use Liberu\Billing\Hosting\Queries\ListHostingRecords;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;
use Liberu\Billing\Hosting\Support\AbstractControlPanelDriver;

it('validates hosting capability, server, transition, and operation actions', function (): void {
    expect(fn () => (new CreateHostingCapability())->handle(0, ['type' => 'plan', 'name' => 'Starter']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => (new CreateHostingServer())->handle(0, ['name' => '', 'hostname' => '', 'control_panel' => 'invalid']))
        ->toThrow(InvalidArgumentException::class);

    Event::fake([HostingServerCreated::class]);
    $server = (new CreateHostingServer())->handle(1, [
        'name' => 'Web01',
        'hostname' => 'web01.example.test',
        'control_panel' => 'cpanel',
        'api_token' => 'secret-token',
        'metadata' => ['region' => 'eu'],
    ]);
    Event::assertDispatched(HostingServerCreated::class);
    expect($server->api_token)->toBe('secret-token')
        ->and($server->is_active)->toBeTrue()
        ->and(HostingServer::query()->forTeam(1)->pluck('id')->all())->toBe([$server->id]);

    $capability = (new CreateHostingCapability())->handle(1, ['type' => 'plan', 'name' => 'Pro']);
    expect(fn () => (new TransitionHostingCapability())->handle($capability, 'invalid-status'))
        ->toThrow(InvalidArgumentException::class);

    $registry = new HostingDriverRegistry();
    $basicDriver = new class() implements HostingDriver
    {
        public function key(): string
        {
            return 'basic-driver';
        }

        public function provision(array $attributes): array
        {
            return ['op' => 'provision'];
        }

        public function suspend(array $attributes): array
        {
            return ['op' => 'suspend'];
        }

        public function terminate(array $attributes): array
        {
            return ['op' => 'terminate'];
        }
    };
    $panelDriver = new class() implements ControlPanelDriver
    {
        public function key(): string
        {
            return 'panel-driver';
        }

        public function provision(array $attributes): array
        {
            return ['op' => 'provision'];
        }

        public function suspend(array $attributes): array
        {
            return ['op' => 'suspend'];
        }

        public function terminate(array $attributes): array
        {
            return ['op' => 'terminate'];
        }

        public function unsuspend(array $attributes): array
        {
            return ['op' => 'unsuspend'];
        }

        public function changePackage(array $attributes): array
        {
            return ['op' => 'changePackage'];
        }

        public function addAddon(array $attributes): array
        {
            return ['op' => 'addAddon'];
        }

        public function removeAddon(array $attributes): array
        {
            return ['op' => 'removeAddon'];
        }
    };
    $registry->register($basicDriver);
    $registry->register($panelDriver);

    $perform = new PerformHostingOperation($registry);

    $noDriverAccount = (new CreateHostingAccount())->handle(1, ['name' => 'No Driver', 'status' => 'pending']);
    expect(fn () => $perform->handle($noDriverAccount, 'bogus'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $perform->handle($noDriverAccount, 'suspend'))->toThrow(LogicException::class)
        ->and(fn () => $perform->handle($noDriverAccount, 'provision'))->toThrow(InvalidArgumentException::class);

    $basicAccount = (new CreateHostingAccount())->handle(1, ['name' => 'Basic', 'status' => 'suspended', 'metadata' => ['driver' => 'basic-driver']]);
    expect(fn () => $perform->handle($basicAccount, 'unsuspend'))->toThrow(InvalidArgumentException::class);

    $panelAccount = (new CreateHostingAccount())->handle(1, [
        'name' => 'Panel Account',
        'status' => 'pending',
        'metadata' => ['driver' => 'panel-driver', 'account' => ['username' => 'u1'], 'server' => ['hostname' => 'h1']],
    ]);
    $panelAccount = $perform->handle($panelAccount, 'provision');
    $panelAccount = $perform->handle($panelAccount, 'change_package');
    $panelAccount = $perform->handle($panelAccount, 'add_addon');
    $panelAccount = $perform->handle($panelAccount, 'remove_addon');
    $panelAccount = $perform->handle($panelAccount, 'suspend');
    $panelAccount = $perform->handle($panelAccount, 'unsuspend');
    $panelAccount = $perform->handle($panelAccount, 'terminate');
    expect($panelAccount->status)->toBe('cancelled');
});

it('lists hosting records and evaluates hosting policies', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.hosting.read', 'billing.hosting.write']);

    $account = (new CreateHostingAccount())->handle($user->current_team_id, ['name' => 'Site A']);
    $other = (new CreateHostingAccount())->handle($user->current_team_id + 1, ['name' => 'Site B']);
    expect((new ListHostingRecords())->handle($user->current_team_id)->pluck('id')->all())->toBe([$account->id]);

    $accountPolicy = new HostingAccountPolicy();
    expect($accountPolicy->viewAny($user))->toBeTrue()
        ->and($accountPolicy->create($user))->toBeTrue()
        ->and($accountPolicy->view($user, $account))->toBeTrue()
        ->and($accountPolicy->update($user, $account))->toBeTrue()
        ->and($accountPolicy->view($user, $other))->toBeFalse()
        ->and($accountPolicy->viewAny(null))->toBeFalse();

    $capability = (new CreateHostingCapability())->handle($user->current_team_id, ['type' => 'ssl', 'name' => 'AutoSSL']);
    $capPolicy = new HostingCapabilityPolicy();
    expect($capPolicy->viewAny($user))->toBeTrue()
        ->and($capPolicy->create($user))->toBeTrue()
        ->and($capPolicy->view($user, $capability))->toBeTrue()
        ->and($capPolicy->update($user, $capability))->toBeTrue()
        ->and($capPolicy->viewAny(null))->toBeFalse();
});

it('exercises all AbstractControlPanelDriver helpers and HTTP error paths', function (): void {
    $mock = new MockHandler([
        new Response(200, [], json_encode(['ok' => true])),
        new Response(500, [], json_encode(['error' => 'boom'])),
        new Response(200, [], 'not-json'),
        new TransferException('network down'),
        new Response(200, [], 'status=ok&code=1'),
        new Response(502, [], 'bad gateway'),
        new TransferException('form network down'),
        new Response(200, [], '<ok/>'),
        new Response(503, [], '<err/>'),
        new TransferException('xml network down'),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    $makeDriver = static fn (?Client $c = null) => new class($c) extends AbstractControlPanelDriver
    {
        public function callServer(array $a): array
        {
            return $this->server($a);
        }

        public function callAccount(array $a): array
        {
            return $this->account($a);
        }

        public function callBaseUrl(array $s): string
        {
            return $this->baseUrl($s);
        }

        public function callToken(array $s): string
        {
            return $this->token($s);
        }

        public function callPassword(array $a): string
        {
            return $this->password($a);
        }

        public function callRequestJson(string $m, string $u, array $s): array
        {
            return $this->requestJson($m, $u, $s);
        }

        public function callRequestForm(string $m, string $u, array $s): array
        {
            return $this->requestForm($m, $u, $s);
        }

        public function callRequestXml(string $m, string $u, array $s, string $b): string
        {
            return $this->requestXml($m, $u, $s, $b);
        }

        public function callAssertSuccessful(array $r): void
        {
            $this->assertSuccessful($r);
        }
    };

    $defaultDriver = $makeDriver();
    expect($defaultDriver)->toBeInstanceOf(AbstractControlPanelDriver::class);

    $driver = $makeDriver($client);

    $arrayable = new class() implements Arrayable
    {
        public function toArray(): array
        {
            return ['hostname' => 'panel.test', 'username' => 'alice', 'domain' => 'alice.test'];
        }
    };

    expect($driver->callServer(['server' => $arrayable]))->toHaveKey('hostname', 'panel.test')
        ->and(fn () => $driver->callServer(['server' => 'invalid']))->toThrow(InvalidArgumentException::class)
        ->and($driver->callAccount(['account' => $arrayable]))->toHaveKey('username', 'alice')
        ->and(fn () => $driver->callAccount(['account' => 'invalid']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $driver->callAccount(['username' => 'alice']))->toThrow(InvalidArgumentException::class)
        ->and($driver->callBaseUrl(['api_url' => 'https://api.panel.test/']))->toBe('https://api.panel.test')
        ->and($driver->callBaseUrl(['hostname' => 'panel.test']))->toBe('https://panel.test')
        ->and(fn () => $driver->callBaseUrl([]))->toThrow(InvalidArgumentException::class)
        ->and($driver->callToken(['token' => 'tok-123']))->toBe('tok-123')
        ->and(fn () => $driver->callToken([]))->toThrow(InvalidArgumentException::class)
        ->and($driver->callPassword(['password' => 'explicit']))->toBe('explicit')
        ->and(strlen($driver->callPassword([])))->toBe(32);

    $srv = ['hostname' => 'panel.test'];
    expect($driver->callRequestJson('get', '/ping', $srv))->toBe(['ok' => true])
        ->and(fn () => $driver->callRequestJson('get', '/fail', $srv))->toThrow(RuntimeException::class)
        ->and(fn () => $driver->callRequestJson('get', '/badjson', $srv))->toThrow(RuntimeException::class)
        ->and(fn () => $driver->callRequestJson('get', '/exc', $srv))->toThrow(RuntimeException::class)
        ->and($driver->callRequestForm('post', '/form', $srv))->toBe(['status' => 'ok', 'code' => '1'])
        ->and(fn () => $driver->callRequestForm('post', '/formfail', $srv))->toThrow(RuntimeException::class)
        ->and(fn () => $driver->callRequestForm('post', '/formexc', $srv))->toThrow(RuntimeException::class)
        ->and($driver->callRequestXml('post', '/xml', $srv, '<req/>'))->toBe('<ok/>')
        ->and(fn () => $driver->callRequestXml('post', '/xmlfail', $srv, '<req/>'))->toThrow(RuntimeException::class)
        ->and(fn () => $driver->callRequestXml('post', '/xmlexc', $srv, '<req/>'))->toThrow(RuntimeException::class);

    $driver->callAssertSuccessful(['status' => 'ok', 'success' => true]);
    expect(fn () => $driver->callAssertSuccessful(['status' => 'error', 'message' => 'Denied']))
        ->toThrow(RuntimeException::class, 'Denied');
});
