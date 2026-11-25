<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('manages portal items and requests through the customer portal API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.customer-portal.read', 'billing.customer-portal.write']);

    $itemId = $this->postJson(route('billing.customer-portal.items.store'), [
        'type' => 'tickets',
        'subject' => 'API Support Ticket',
        'payload' => ['source' => 'api'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'customer-portal-item')
        ->assertJsonPath('data.attributes.subject', 'API Support Ticket')
        ->json('data.id');

    $this->getJson(route('billing.customer-portal.items.index', ['type' => 'tickets', 'per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $itemId)
        ->assertJsonPath('meta.total', 1);

    $this->patchJson(route('billing.customer-portal.items.status', ['item' => $itemId]), [
        'status' => 'completed',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'completed');

    $requestId = $this->postJson(route('billing.customer-portal.requests.store'), [
        'name' => 'API Plan Upgrade',
        'status' => 'active',
        'metadata' => ['tier' => 'enterprise'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'customer-portal-request')
        ->assertJsonPath('data.attributes.name', 'API Plan Upgrade')
        ->json('data.id');

    $this->getJson(route('billing.customer-portal.requests.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $requestId)
        ->assertJsonPath('meta.total', 1);

    $this->getJson(route('billing.customer-portal.requests.show', ['record' => $requestId]))
        ->assertOk()
        ->assertJsonPath('data.id', $requestId);

    $this->patchJson(route('billing.customer-portal.requests.status', ['record' => $requestId]), [
        'status' => 'closed',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'closed');
});
