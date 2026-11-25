<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Collections\Actions\OpenCollectionCase;
use Liberu\Billing\Collections\Enums\CollectionStatus;
use Liberu\Billing\Collections\Filament\CollectionsFilamentPlugin;
use Liberu\Billing\Collections\Filament\Resources\CollectionCaseResource;
use Liberu\Billing\Collections\Filament\Resources\CollectionCaseResource\Pages\CreateCollectionCase;
use Liberu\Billing\Collections\Filament\Resources\CollectionCaseResource\Pages\ListCollectionCases;
use Liberu\Billing\Collections\Models\CollectionCase;
use Livewire\Livewire;

it('creates and operates on collection cases through Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.collections.read', 'billing.collections.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);
    CollectionsFilamentPlugin::make()->boot($panel);

    Livewire::test(CreateCollectionCase::class)->fillForm([
        'amount_minor' => 2500,
        'currency' => 'USD',
        'type' => 'dunning',
    ])->call('create')->assertHasNoFormErrors();

    $case = CollectionCase::query()->latest('id')->firstOrFail();
    $case2 = app(OpenCollectionCase::class)->execute([
        'team_id' => $actor->current_team_id,
        'amount_minor' => 1800,
        'currency' => 'USD',
    ]);

    $future = now()->addDay()->format('Y-m-d\TH:i');

    $table = Livewire::test(ListCollectionCases::class)
        ->removeTableFilter('needs_attention')
        ->assertCanSeeTableRecords([$case, $case2])
        ->callTableAction('promise', $case, data: ['due_at' => $future])
        ->callTableAction('retry', $case, data: ['next_action_at' => $future])
        ->callTableAction('dunning', $case, data: ['next_action_at' => $future])
        ->callTableAction('reminder', $case, data: ['next_action_at' => $future])
        ->callTableAction('credit_control', $case, data: ['level' => 'warning', 'reason' => 'Escalated'])
        ->callTableAction('suspend', $case, data: ['reason' => 'Unpaid'])
        ->callTableAction('recover', $case)
        ->callTableAction('write_off', $case2, data: ['reason' => 'Uncollectible']);

    expect($case->fresh()->status)->toBe(CollectionStatus::Recovered)
        ->and($case2->fresh()->status)->toBe(CollectionStatus::WrittenOff);
});

it('scopes Filament collection case queries to shared records without an active team', function (): void {
    $open = app(OpenCollectionCase::class);
    $shared = $open->execute(['amount_minor' => 900, 'currency' => 'USD']);
    $open->execute(['team_id' => 777, 'amount_minor' => 900, 'currency' => 'USD']);

    $this->actingAs(User::factory()->create(), 'web');

    expect(CollectionCaseResource::getEloquentQuery()->pluck('id')->all())->toBe([$shared->id]);
});
