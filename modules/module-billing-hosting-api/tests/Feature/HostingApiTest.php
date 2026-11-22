<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Hosting\Contracts\HostingDriver;
use Liberu\Billing\Hosting\Services\HostingDriverRegistry;

it('runs full hosting account and capability lifecycle through the API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.hosting.read', 'billing.hosting.write']);

    app(HostingDriverRegistry::class)->register(new class() implements HostingDriver
    {
        public function key(): string
        {
            return 'api-hosting-driver';
        }

        public function provision(array $attributes): array
        {
            return ['provisioned' => true];
        }

        public function suspend(array $attributes): array
        {
            return ['suspended' => true];
        }

        public function terminate(array $attributes): array
        {
            return ['terminated' => true];
        }
    });

    $accountId = $this->postJson(route('billing.hosting.store'), [
        'name' => 'Main Shared Hosting',
        'status' => 'pending',
        'metadata' => ['driver' => 'api-hosting-driver'],
    ])->assertCreated()->assertJsonPath('data.type', 'hosting-account')->json('data.id');

    $this->getJson(route('billing.hosting.accounts.index', ['per_page' => 5]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $accountId);

    $this->getJson(route('billing.hosting.accounts.show', $accountId))
        ->assertOk()
        ->assertJsonPath('data.id', $accountId);

    $this->postJson(route('billing.hosting.accounts.operation', $accountId), [
        'operation' => 'provision',
        'account' => ['username' => 'site1'],
        'server' => ['hostname' => 'srv1.test'],
    ])->assertOk()->assertJsonPath('data.attributes.status', 'active');

    $this->patchJson(route('billing.hosting.accounts.lifecycle', $accountId), ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'suspended');

    $capabilityId = $this->postJson(route('billing.hosting.capabilities.store'), [
        'type' => 'ssl',
        'name' => 'Wildcard SSL',
        'hosting_account_id' => (int) $accountId,
        'provider' => 'letsencrypt',
    ])->assertCreated()->assertJsonPath('data.type', 'hosting-capability')->json('data.id');

    $this->getJson(route('billing.hosting.capabilities.index', ['type' => 'ssl']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $capabilityId);

    $this->patchJson(route('billing.hosting.capabilities.lifecycle', $capabilityId), ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');
});
