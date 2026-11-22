<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('manages communication services, numbers, providers, and usage imports through the API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.communications.read', 'billing.communications.write']);

    $serviceId = $this->postJson(route('billing.communications.store'), [
        'name' => 'Primary SIP',
        'status' => 'active',
    ])->assertCreated()->json('data.id');

    $this->getJson(route('billing.communications.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $serviceId);

    $this->getJson(route('billing.communications.show', ['record' => $serviceId]))
        ->assertOk()
        ->assertJsonPath('data.id', $serviceId);

    $this->patchJson(route('billing.communications.services.lifecycle', ['service' => $serviceId]), [
        'status' => 'suspended',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'suspended');

    $numberId = $this->postJson(route('billing.communications.numbers.store'), [
        'number' => '+15557654321',
        'type' => 'did',
        'service_id' => (int) $serviceId,
    ])->assertCreated()->json('data.id');

    $this->getJson(route('numbers'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $numberId);

    $this->patchJson(route('billing.communications.numbers.status', ['number' => $numberId]), [
        'status' => 'suspended',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'suspended');

    $providerId = $this->postJson(route('billing.communications.providers.store'), [
        'name' => 'Carrier One',
        'driver' => 'sip',
        'configuration' => ['host' => 'sip.example.test'],
    ])->assertCreated()->json('data.id');

    $this->getJson(route('providers'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $providerId);

    $importId = $this->postJson(route('billing.communications.usage-imports.store'), [
        'provider' => 'sip',
        'rows' => 15,
        'total_amount_minor' => 990,
        'currency' => 'USD',
    ])->assertCreated()->json('data.id');

    $this->getJson(route('usage-imports'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $importId);
});
