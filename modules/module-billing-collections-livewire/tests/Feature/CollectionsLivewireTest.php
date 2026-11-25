<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Collections\Actions\OpenCollectionCase;
use Liberu\Billing\Collections\Enums\CollectionStatus;
use Liberu\Billing\Collections\Livewire\Components\CollectionCaseList;
use Liberu\Billing\Collections\Models\CollectionCase;
use Livewire\Livewire;

it('creates and operates on collection cases through the Livewire component', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.collections.read', 'billing.collections.write']);

    $component = Livewire::test(CollectionCaseList::class)
        ->set('showCreate', true)
        ->set('amountMinor', 3200)
        ->set('currency', 'USD')
        ->call('create')
        ->assertHasNoErrors();

    $case = CollectionCase::query()->latest('id')->firstOrFail();
    $case2 = app(OpenCollectionCase::class)->execute([
        'team_id' => $user->current_team_id,
        'amount_minor' => 1500,
        'currency' => 'USD',
    ]);

    $future = now()->addDay()->toIso8601String();

    $component->set('operationDate', $future)->call('promise', $case->id)->assertHasNoErrors()
        ->set('operationDate', $future)->call('retry', $case->id)->assertHasNoErrors()
        ->set('operationDate', $future)->call('dunning', $case->id)->assertHasNoErrors()
        ->set('operationDate', $future)->call('reminder', $case->id)->assertHasNoErrors()
        ->set('creditControlLevel', 'final')->set('operationReason', 'Final notice')->call('creditControl', $case->id)->assertHasNoErrors()
        ->set('operationReason', 'Suspended')->call('suspend', $case->id)->assertHasNoErrors()
        ->call('recover', $case->id)->assertHasNoErrors()
        ->set('operationReason', 'Written off')->call('writeOff', $case2->id)->assertHasNoErrors();

    expect($case->fresh()->status)->toBe(CollectionStatus::Recovered)
        ->and($case2->fresh()->status)->toBe(CollectionStatus::WrittenOff);
});

it('operates on shared collection cases when the actor has no current team', function (): void {
    $shared = app(OpenCollectionCase::class)->execute(['amount_minor' => 500, 'currency' => 'USD']);
    $actor = User::factory()->create();
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.collections.read', 'billing.collections.write']);

    Livewire::test(CollectionCaseList::class)
        ->call('recover', $shared->id)
        ->assertHasNoErrors();

    expect($shared->fresh()->status)->toBe(CollectionStatus::Recovered);
});
