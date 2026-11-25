<?php

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\UsageImportService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Usage\Actions\CheckUsageThreshold;
use Liberu\Billing\Usage\Actions\CorrectUsage;
use Liberu\Billing\Usage\Actions\DefineMeter;
use Liberu\Billing\Usage\Actions\IngestUsage;
use Liberu\Billing\Usage\Actions\RateUsage;
use Liberu\Billing\Usage\Actions\TransitionMeter;
use Liberu\Billing\Usage\Events\MeterDefined;
use Liberu\Billing\Usage\Events\MeterStatusChanged;
use Liberu\Billing\Usage\Events\UsageCorrected;
use Liberu\Billing\Usage\Events\UsageIngested;
use Liberu\Billing\Usage\Models\Meter;
use Liberu\Billing\Usage\Models\UsageRecord;
use Liberu\Billing\Usage\Queries\AggregateUsage;
use Liberu\Billing\Usage\Queries\ListMeters;
use Liberu\Billing\Usage\Queries\ListUsageRecords;
use Liberu\Billing\Usage\Support\CustomerReference;

uses(RefreshDatabase::class);

it('defines meters, deduplicates usage, aggregates, and corrects records', function () {
    Event::fake([MeterDefined::class, MeterStatusChanged::class, UsageIngested::class, UsageCorrected::class]);

    $meter = app(DefineMeter::class)->execute(['team_id' => 10, 'code' => 'api_calls', 'currency' => 'USD', 'unit_price_minor' => 5]);
    $record = app(IngestUsage::class)->execute($meter, ['event_key' => 'evt-1', 'quantity' => 3]);
    $duplicate = app(IngestUsage::class)->execute($meter, ['event_key' => 'evt-1', 'quantity' => 99]);
    $correction = app(CorrectUsage::class)->execute($record, -1, ' correction-1 ');
    $duplicateCorrection = app(CorrectUsage::class)->execute($record, -99, 'correction-1');
    $aggregate = app(AggregateUsage::class)->execute($meter->getKey());

    expect($duplicate->is($record))->toBeTrue()
        ->and($duplicateCorrection->is($correction))->toBeTrue()
        ->and((float) $aggregate->quantity)->toBe(2.0)
        ->and((int) $aggregate->amount_minor)->toBe(10);

    Event::assertDispatchedTimes(MeterDefined::class, 1);
    Event::assertDispatchedTimes(UsageIngested::class, 1);
    Event::assertDispatchedTimes(UsageCorrected::class, 1);
});

it('rejects invalid usage events', function () {
    expect(fn () => app(DefineMeter::class)->execute(['code' => '', 'currency' => 'USD', 'unit_price_minor' => 1]))
        ->toThrow(InvalidArgumentException::class);
});

it('transitions meter activity through the domain action', function (): void {
    Event::fake([MeterStatusChanged::class]);
    $meter = app(DefineMeter::class)->execute(['team_id' => 10, 'code' => 'status-meter', 'currency' => 'USD', 'unit_price_minor' => 1]);

    $inactive = app(TransitionMeter::class)->execute($meter, false);

    expect($inactive->active)->toBeFalse();
    Event::assertDispatchedTimes(MeterStatusChanged::class, 1);
    expect(app(TransitionMeter::class)->execute($inactive, false)->active)->toBeFalse();
    Event::assertDispatchedTimes(MeterStatusChanged::class, 1);
});

it('normalizes meter identifiers and rejects invalid thresholds', function () {
    $meter = app(DefineMeter::class)->execute([
        'team_id' => 10,
        'code' => ' API.Calls ',
        'name' => ' API calls ',
        'unit' => ' request ',
        'currency' => 'usd',
        'unit_price_minor' => 5,
        'threshold' => '100.5',
    ]);

    expect($meter->code)->toBe('api.calls')
        ->and($meter->name)->toBe('API calls')
        ->and($meter->unit)->toBe('request')
        ->and((float) $meter->threshold)->toBe(100.5);

    expect(fn () => app(DefineMeter::class)->execute([
        'code' => 'requests', 'currency' => 'USD', 'unit_price_minor' => 1, 'threshold' => -1,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rates usage progressively across sorted tiers', function () {
    $meter = app(DefineMeter::class)->execute([
        'team_id' => 10,
        'code' => 'bandwidth',
        'currency' => 'USD',
        'unit_price_minor' => 10,
        'metadata' => ['tiers' => [
            ['up_to' => 20, 'unit_price_minor' => 4],
            ['up_to' => 10, 'unit_price_minor' => 5],
        ]],
    ]);

    expect(app(RateUsage::class)->execute($meter, 25))->toBe(140)
        ->and(app(RateUsage::class)->execute($meter, 5))->toBe(25);

    $meter->update(['metadata' => ['tiers' => [['up_to' => 0, 'unit_price_minor' => 1]]]]);
    expect(fn () => app(RateUsage::class)->execute($meter, 1))
        ->toThrow(InvalidArgumentException::class);
});

it('does not ingest usage after the persisted meter is deactivated', function (): void {
    $meter = app(DefineMeter::class)->execute([
        'team_id' => 10, 'code' => 'requests', 'currency' => 'USD', 'unit_price_minor' => 1,
    ]);
    $meter->refresh();
    Meter::query()->whereKey($meter->getKey())->update(['active' => false]);

    expect(fn () => app(IngestUsage::class)->execute($meter, ['event_key' => 'evt-inactive', 'quantity' => 1]))
        ->toThrow(LogicException::class, 'Usage cannot be ingested into an inactive meter.');
});

it('rejects usage for a customer owned by another team', function (): void {
    $team = Team::factory()->create(['id' => 20]);
    $customerId = Customer::factory()->create(['team_id' => $team->getKey()])->getKey();
    $meter = app(DefineMeter::class)->execute([
        'team_id' => 10, 'code' => 'requests', 'currency' => 'USD', 'unit_price_minor' => 1,
    ]);

    expect(fn () => app(IngestUsage::class)->execute($meter, [
        'customer_id' => $customerId, 'event_key' => 'evt-foreign', 'quantity' => 1,
    ]))->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');
});

it('lists shared and current-team meters and filters usage by meter', function (): void {
    $definition = ['currency' => 'USD', 'unit_price_minor' => 5];
    $shared = app(DefineMeter::class)->execute($definition + ['code' => 'shared']);
    $own = app(DefineMeter::class)->execute($definition + ['code' => 'own', 'team_id' => 10]);
    $foreign = app(DefineMeter::class)->execute($definition + ['code' => 'foreign', 'team_id' => 20]);

    $sharedUsage = app(IngestUsage::class)->execute($shared, ['event_key' => 'shared-1', 'quantity' => 2]);
    $ownUsage = app(IngestUsage::class)->execute($own, ['event_key' => 'own-1', 'quantity' => 3]);
    app(IngestUsage::class)->execute($foreign, ['event_key' => 'foreign-1', 'quantity' => 4]);

    expect(app(ListMeters::class)->execute()->modelKeys())->toBe([$shared->id])
        ->and(app(ListMeters::class)->execute(10)->modelKeys())->toBe([$own->id, $shared->id])
        ->and(app(ListUsageRecords::class)->execute()->modelKeys())->toBe([$sharedUsage->id])
        ->and(app(ListUsageRecords::class)->execute(10, $own->id)->modelKeys())->toBe([$ownUsage->id])
        ->and(app(ListUsageRecords::class)->execute(10, $foreign->id))->toBeEmpty();
});

it('treats usage equal to the configured threshold as reached', function (): void {
    $meter = new Meter(['threshold' => 10.5]);
    $threshold = app(CheckUsageThreshold::class);

    expect($threshold->execute($meter, 10.49))->toBeFalse()
        ->and($threshold->execute($meter, 10.5))->toBeTrue()
        ->and($threshold->execute(new Meter(), 100))->toBeFalse();
});

it('rates flat usage and rejects invalid meter inputs', function (): void {
    $meter = new Meter(['unit_price_minor' => 7, 'active' => true]);
    $rating = app(RateUsage::class);

    expect($rating->execute($meter, 1.5))->toBe(11)
        ->and(fn () => $rating->execute($meter, -1))->toThrow(InvalidArgumentException::class);

    $meter->forceFill(['active' => false]);
    expect(fn () => $rating->execute($meter, 1))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid quantities before writing usage records', function (): void {
    $meter = app(DefineMeter::class)->execute(['code' => 'validation', 'currency' => 'USD', 'unit_price_minor' => 1]);
    $ingest = app(IngestUsage::class);

    expect(fn () => $ingest->execute($meter, ['event_key' => 'invalid', 'quantity' => INF]))
        ->toThrow(InvalidArgumentException::class);

    $record = $ingest->execute($meter, ['event_key' => 'valid', 'quantity' => 1]);
    expect(fn () => app(CorrectUsage::class)->execute($record, 0, 'invalid-correction'))
        ->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseMissing($record->getTable(), ['event_key' => 'invalid-correction']);
});

it('enforces read and write abilities and team ownership for meters and usage', function (): void {
    $actor = new User();
    $actor->forceFill(['current_team_id' => 10]);
    $ownMeter = new Meter(['team_id' => 10]);
    $foreignMeter = new Meter(['team_id' => 20]);
    $ownUsage = new UsageRecord(['team_id' => 10]);
    $foreignUsage = new UsageRecord(['team_id' => 20]);

    Sanctum::actingAs($actor, ['billing.usage.read']);

    expect(Gate::allows('viewAny', Meter::class))->toBeTrue()
        ->and(Gate::allows('viewAny', UsageRecord::class))->toBeTrue()
        ->and(Gate::allows('view', $ownMeter))->toBeTrue()
        ->and(Gate::allows('view', $ownUsage))->toBeTrue()
        ->and(Gate::denies('view', $foreignMeter))->toBeTrue()
        ->and(Gate::denies('view', $foreignUsage))->toBeTrue()
        ->and(Gate::denies('create', Meter::class))->toBeTrue()
        ->and(Gate::denies('create', UsageRecord::class))->toBeTrue();

    Sanctum::actingAs($actor, ['billing.usage.write']);

    expect(Gate::allows('update', $ownMeter))->toBeTrue()
        ->and(Gate::denies('update', $foreignMeter))->toBeTrue()
        ->and(Gate::allows('create', UsageRecord::class))->toBeTrue()
        ->and(Gate::denies('view', $ownMeter))->toBeTrue();
});

it('records usage against a customer from the meter team', function (): void {
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $meter = app(DefineMeter::class)->execute([
        'code' => 'customer-requests', 'team_id' => $team->id, 'currency' => 'USD', 'unit_price_minor' => 1,
    ]);

    $record = app(IngestUsage::class)->execute($meter, [
        'event_key' => 'customer-usage', 'quantity' => 1, 'customer_id' => $customer->id,
    ]);

    expect($record->customer_id)->toBe($customer->id);
});

it('returns the winning record after a concurrent duplicate insert', function (): void {
    $meter = app(DefineMeter::class)->execute(['code' => 'retry', 'currency' => 'USD', 'unit_price_minor' => 1]);
    $attributes = ['event_key' => 'duplicate', 'quantity' => 1];
    $winner = app(IngestUsage::class)->execute($meter, $attributes);
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('transaction')->once()->andThrow(
        new QueryException('sqlite', 'insert into billing_usage_records', [], new RuntimeException('UNIQUE constraint failed')),
    );

    expect((new IngestUsage($database))->execute($meter, $attributes)->is($winner))->toBeTrue();
});

it('propagates database errors unrelated to duplicate usage', function (): void {
    $exception = new QueryException('sqlite', 'insert into billing_usage_records', [], new RuntimeException('Database unavailable'));
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('transaction')->once()->andThrow($exception);

    expect(fn () => (new IngestUsage($database))->execute(new Meter(), ['event_key' => 'usage', 'quantity' => 1]))
        ->toThrow(fn (QueryException $error) => expect($error)->toBe($exception));
});

it('rejects customer references before the host customer table is available', function (): void {
    Schema::partialMock()->shouldReceive('hasTable')->with('customers')->once()->andReturnFalse();

    expect(fn () => CustomerReference::assertBelongsToTeam(app(DatabaseManager::class), 1, 10))
        ->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');
});

it('rejects negative imported usage and keeps valid records linked to their subscription', function (): void {
    $subscription = Subscription::factory()->create();
    $import = app(UsageImportService::class);

    expect(fn () => $import->importUsage($subscription, 'bandwidth', -1))
        ->toThrow(InvalidArgumentException::class, 'Usage quantity cannot be negative.');

    $record = $import->importUsage($subscription, 'bandwidth', 2.5);

    expect($record->subscription->is($subscription))->toBeTrue()
        ->and($record->quantity)->toBe('2.50');
});
