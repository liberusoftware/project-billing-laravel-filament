<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('manages products and catalog records through the catalog API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.catalog.read', 'billing.catalog.write']);

    $productId = $this->postJson(route('billing.catalog.products.store'), [
        'name' => 'Cloud Server',
        'sku' => 'cloud-srv',
        'description' => 'Managed instance',
        'base_price_minor' => 1999,
        'currency' => 'USD',
        'metadata' => ['region' => 'eu'],
    ])->assertCreated()
        ->assertJsonPath('data.attributes.sku', 'CLOUD-SRV')
        ->json('data.id');

    $this->getJson(route('billing.catalog.products.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $productId)
        ->assertJsonPath('meta.last_page', 1);

    $this->patchJson(route('billing.catalog.products.lifecycle', $productId), ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');

    foreach (['plans', 'addons', 'bundles', 'options', 'eligibility', 'channels'] as $type) {
        $recordId = $this->postJson(route('billing.catalog.records.store', ['type' => $type]), [
            'name' => "Sample {$type}",
            'code' => "code-{$type}",
            'description' => 'Details',
            'configuration' => ['enabled' => true],
        ])->assertCreated()
            ->assertJsonPath('data.type', "billing-catalog-{$type}")
            ->json('data.id');

        $this->getJson(route('billing.catalog.records.index', ['type' => $type]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $recordId);

        $this->patchJson(route('billing.catalog.records.lifecycle', ['type' => $type, 'record' => $recordId]), [
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.attributes.status', 'active');
    }
});
