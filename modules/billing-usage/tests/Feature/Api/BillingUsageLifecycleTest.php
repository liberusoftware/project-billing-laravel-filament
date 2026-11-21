<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Usage\Actions\DefineMeter;

it('runs a meter lifecycle through the published usage API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.usage.read', 'billing.usage.write']);

    $meter = $this->postJson(route('billing.usage.meters.store'), [
        'code' => 'requests', 'unit' => 'request', 'currency' => 'usd', 'unit_price_minor' => 5, 'threshold' => 3,
    ])->assertCreated()->assertJsonPath('data.attributes.code', 'requests')->json('data.id');

    $this->getJson(route('billing.usage.meters', ['per_page' => 1]))
        ->assertOk()->assertJsonPath('data.0.id', $meter)->assertJsonPath('meta.last_page', 1);

    $this->postJson(route('billing.usage.rate', $meter), ['quantity' => 3])
        ->assertOk()->assertJsonPath('data.amount_minor', 15)->assertJsonPath('data.threshold_reached', true);

    $record = $this->postJson(route('billing.usage.records.ingest', $meter), ['event_key' => 'requests-1', 'quantity' => 3])
        ->assertCreated()->assertJsonPath('data.attributes.amount_minor', 15)->json('data.id');

    $this->getJson(route('billing.usage.records.index', ['meter_id' => $meter]))
        ->assertOk()->assertJsonPath('data.0.id', $record);

    $this->postJson(route('billing.usage.records.correct', $record), ['event_key' => 'correction-1', 'quantity' => -1])
        ->assertCreated()->assertJsonPath('data.attributes.amount_minor', -5);

    $this->getJson(route('billing.usage.aggregate', $meter))->assertOk()->assertJsonPath('data.amount_minor', 10);
    $this->getJson(route('billing.usage.records.aggregate', ['meter_id' => $meter]))
        ->assertOk()->assertJsonPath('data.amount_minor', 10);

    $this->patchJson(route('billing.usage.meters.status', $meter), ['active' => false])
        ->assertOk()->assertJsonPath('data.attributes.active', false);
});

it('lists only shared meters for an actor with no team', function (): void {
    $attributes = ['currency' => 'USD', 'unit_price_minor' => 5];
    $shared = app(DefineMeter::class)->execute($attributes + ['code' => 'shared']);
    app(DefineMeter::class)->execute($attributes + ['code' => 'private-meter', 'team_id' => 42]);

    Sanctum::actingAs(User::factory()->create(), ['billing.usage.read']);

    $this->getJson(route('billing.usage.meters'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $shared->id);
});
