<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('runs the full collection case lifecycle through the collections API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.collections.read', 'billing.collections.write']);

    $caseId = $this->postJson(route('billing.collections.store'), [
        'type' => 'dunning',
        'amount_minor' => 4500,
        'currency' => 'USD',
        'reason' => 'Past due',
    ])->assertCreated()
        ->assertJsonPath('data.attributes.amount_minor', 4500)
        ->json('data.id');

    $this->getJson(route('billing.collections.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $caseId);

    $future = now()->addDays(2)->toIso8601String();

    $this->postJson(route('billing.collections.promise', $caseId), ['due_at' => $future])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'promised');

    $this->postJson(route('billing.collections.retry', $caseId), ['next_action_at' => $future])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'open');

    $this->postJson(route('billing.collections.dunning', $caseId), ['next_action_at' => $future])
        ->assertOk()
        ->assertJsonPath('data.attributes.type', 'dunning');

    $this->postJson(route('billing.collections.reminder', $caseId), ['next_action_at' => $future])
        ->assertOk()
        ->assertJsonPath('data.attributes.type', 'reminder');

    $this->postJson(route('billing.collections.credit-control', $caseId), [
        'level' => 'warning',
        'reason' => 'Second notice',
    ])->assertOk()
        ->assertJsonPath('data.attributes.type', 'credit_control');

    $this->postJson(route('billing.collections.suspend', $caseId), ['reason' => 'Account suspended'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'suspended');

    $this->postJson(route('billing.collections.recover', $caseId))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'recovered');

    $writeOffCaseId = $this->postJson(route('billing.collections.store'), [
        'amount_minor' => 1200,
        'currency' => 'USD',
    ])->assertCreated()->json('data.id');

    $this->postJson(route('billing.collections.write-off', $writeOffCaseId), ['reason' => 'Bad debt'])
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'written_off');
});
