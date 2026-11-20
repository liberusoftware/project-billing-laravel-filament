<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Liberu\ExecutiveInsights\Actions\RegisterMetric;
use Liberu\ExecutiveInsights\Events\MetricRegistered;
use Liberu\ExecutiveInsights\Models\MetricDefinition;
use Liberu\Platform\ExecutiveInsights\Actions\CreateInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Actions\TransitionInsightSnapshot;
use Liberu\Platform\ExecutiveInsights\Enums\LifecycleStatus;
use Liberu\Platform\ExecutiveInsights\Events\InsightSnapshotTransitioned;
use Liberu\Platform\ExecutiveInsights\Exceptions\InvalidLifecycleTransition;
use Liberu\Platform\ExecutiveInsights\Models\InsightSnapshot;

foreach ([
    __DIR__.'/../../src/Models/MetricDefinition.php',
    __DIR__.'/../../src/Events/MetricRegistered.php',
    __DIR__.'/../../src/Actions/RegisterMetric.php',
] as $legacyFile) {
    require_once $legacyFile;
}

it('creates and hydrates its aggregate through the domain action', function (): void {
    $record = (new CreateInsightSnapshot)->execute([
        'tenant_id' => 'team-1',
        'idempotency_key' => 'request-1',
        'name' => 'Sample record',
        'status' => 'draft',
        'metadata' => ['source' => 'archive'],
    ]);

    expect($record)->toBeInstanceOf(InsightSnapshot::class)
        ->and($record->exists)->toBeTrue()
        ->and($record->tenant_id)->toBe('team-1')
        ->and($record->name)->toBe('Sample record')
        ->and($record->status)->toBe('draft')
        ->and(InsightSnapshot::query()->forTenant('team-1')->count())->toBe(1);
});

it('transitions insight snapshots across allowed lifecycle states and rejects invalid transitions', function (): void {
    Event::fake([InsightSnapshotTransitioned::class]);

    $record = (new CreateInsightSnapshot)->execute([
        'tenant_id' => 'team-1',
        'idempotency_key' => 'transition-1',
        'name' => 'Snapshot A',
        'status' => LifecycleStatus::Draft->value,
        'metadata' => [],
    ]);

    $transition = new TransitionInsightSnapshot;
    $active = $transition->execute($record, LifecycleStatus::Active);
    expect($active->status)->toBe(LifecycleStatus::Active->value);

    $completed = $transition->execute($active, LifecycleStatus::Completed);
    expect($completed->status)->toBe(LifecycleStatus::Completed->value);
    expect(LifecycleStatus::Completed->allowedTransitions())->toBe([]);

    expect(fn () => $transition->execute($completed, LifecycleStatus::Active))
        ->toThrow(InvalidLifecycleTransition::class);

    Event::assertDispatched(InsightSnapshotTransitioned::class, 2);
});

it('registers metrics, validates currency and freshness, and scopes by team', function (): void {
    Event::fake([MetricRegistered::class]);

    $action = new RegisterMetric(app(Dispatcher::class));

    expect(fn () => $action->handle([
        'key' => 'rev', 'name' => 'Revenue', 'formula' => 'SUM(a)', 'version' => '1',
        'currency' => 'usd', 'timezone' => 'UTC', 'freshness_seconds' => 60,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $action->handle([
        'key' => 'rev', 'name' => 'Revenue', 'formula' => 'SUM(a)', 'version' => '1',
        'currency' => 'USD', 'timezone' => 'UTC', 'freshness_seconds' => 0,
    ]))->toThrow(ValidationException::class);

    $metric = $action->handle([
        'team_id' => null,
        'key' => 'gross_margin',
        'name' => 'Gross Margin',
        'formula' => '(rev - cogs) / rev',
        'version' => '1.0',
        'currency' => 'USD',
        'timezone' => 'UTC',
        'freshness_seconds' => 300,
    ]);

    expect($metric->is_active)->toBeTrue()
        ->and($metric->dimensions)->toBe([])
        ->and(MetricDefinition::query()->forTeam(null)->count())->toBe(1)
        ->and(MetricDefinition::query()->forTeam(99)->count())->toBe(1);

    Event::assertDispatched(MetricRegistered::class);
});
