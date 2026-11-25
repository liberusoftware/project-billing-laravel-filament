<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Usage\Livewire\Components\MeterList;
use Liberu\Billing\Usage\Livewire\Components\UsageDashboard;
use Liberu\Billing\Usage\Livewire\Components\UsageRecords;
use Liberu\Billing\Usage\Models\Meter;
use Liberu\Billing\Usage\Models\UsageRecord;
use Livewire\Livewire;

it('manages a meter and its usage through the published Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.usage.read', 'billing.usage.write']);

    Livewire::test(UsageDashboard::class)->assertSee('No usage recorded.');

    $meters = Livewire::test(MeterList::class)
        ->set('showCreate', true)
        ->set('name', 'API requests')
        ->set('code', 'api-requests')
        ->set('unitPriceMinor', 5)
        ->call('createMeter')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false)
        ->assertSee('API requests');

    $meter = Meter::query()->where('code', 'api-requests')->sole();
    expect($meter->team_id)->toBe($user->current_team_id);

    $meters->set('selectedMeterId', $meter->id)
        ->set('eventKey', 'requests-1')
        ->set('quantity', 3)
        ->call('ingest')
        ->assertHasNoErrors()
        ->assertSet('eventKey', '');

    $record = UsageRecord::query()->where('event_key', 'requests-1')->sole();

    Livewire::test(UsageRecords::class)
        ->assertSee('requests-1')
        ->set('selectedMeterId', $meter->id)
        ->set('ratingQuantity', 3)
        ->call('rate')
        ->assertHasNoErrors()
        ->assertSee('Usage rated at 15 minor units')
        ->set('selectedRecordId', $record->id)
        ->set('correctionQuantity', -1)
        ->set('correctionEventKey', 'requests-correction')
        ->call('correct')
        ->assertHasNoErrors()
        ->assertSee('requests-correction');

    Livewire::test(UsageDashboard::class, ['meterId' => $meter->id])
        ->assertSee('Quantity')
        ->assertSee('10');

    $meters->call('transitionMeter', $meter->id)->assertHasNoErrors();
    expect($meter->fresh()->active)->toBeFalse();
});
