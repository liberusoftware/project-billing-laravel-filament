<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Usage\Actions\DefineMeter;
use Liberu\Billing\Usage\Filament\Resources\MeterResource;
use Liberu\Billing\Usage\Filament\Resources\MeterResource\Pages\CreateMeter;
use Liberu\Billing\Usage\Filament\Resources\MeterResource\Pages\ListMeters;
use Liberu\Billing\Usage\Filament\Resources\UsageRecordResource\Pages\CreateUsageRecord;
use Liberu\Billing\Usage\Filament\Resources\UsageRecordResource\Pages\ListUsageRecords;
use Liberu\Billing\Usage\Models\Meter;
use Liberu\Billing\Usage\Models\UsageRecord;
use Livewire\Livewire;

it('creates and manages team usage through the published Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.usage.read', 'billing.usage.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    Livewire::test(CreateMeter::class)->fillForm([
        'name' => 'API requests', 'code' => 'api-requests', 'unit' => 'request',
        'unit_price_minor' => 5, 'currency' => 'USD', 'threshold' => 3,
    ])->call('create')->assertHasNoFormErrors();

    $meter = Meter::query()->where('code', 'api-requests')->sole();
    $foreign = app(DefineMeter::class)->execute([
        'code' => 'private-meter', 'team_id' => $actor->current_team_id + 1, 'currency' => 'USD', 'unit_price_minor' => 1,
    ]);

    Livewire::test(CreateUsageRecord::class)->fillForm([
        'meter_id' => $meter->id, 'event_key' => 'meter-request', 'quantity' => 3,
    ])->call('create')->assertHasNoFormErrors();

    $record = UsageRecord::query()->where('event_key', 'meter-request')->sole();
    expect($record->amount_minor)->toBe(15);
    Livewire::test(ListUsageRecords::class)->assertCanSeeTableRecords([$record]);

    Livewire::test(ListMeters::class)
        ->assertCanSeeTableRecords([$meter])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('rate', $meter, data: ['quantity' => 3])
        ->assertNotified('Usage rated')
        ->mountTableAction('status', $meter)
        ->assertTableActionDataSet(['active' => true])
        ->setTableActionData(['active' => false])
        ->callMountedTableAction();

    expect($meter->fresh()->active)->toBeFalse();
});

it('restricts the Filament query to shared meters without a current team', function (): void {
    $attributes = ['currency' => 'USD', 'unit_price_minor' => 5];
    $shared = app(DefineMeter::class)->execute($attributes + ['code' => 'shared']);
    app(DefineMeter::class)->execute($attributes + ['code' => 'private', 'team_id' => 42]);
    Sanctum::actingAs(User::factory()->create(), ['billing.usage.read']);

    expect(MeterResource::getEloquentQuery()->pluck('id')->all())->toBe([$shared->id]);
});
