<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Platform\RevenueAndCareOrchestration\Actions\CreateCarePlan;
use Liberu\Platform\RevenueAndCareOrchestration\Actions\TransitionCarePlan;
use Liberu\Platform\RevenueAndCareOrchestration\Enums\LifecycleStatus;
use Liberu\Platform\RevenueAndCareOrchestration\Events\CarePlanTransitioned;
use Liberu\Platform\RevenueAndCareOrchestration\Exceptions\InvalidLifecycleTransition;
use Liberu\Platform\RevenueAndCareOrchestration\Models\CarePlan;

it('creates and hydrates its aggregate through the domain action', function (): void {
    $record = (new CreateCarePlan)->execute([
        'tenant_id' => 'team-1',
        'idempotency_key' => 'request-1',
        'name' => 'Sample record',
        'status' => 'draft',
        'metadata' => ['source' => 'archive'],
    ]);

    expect($record)->toBeInstanceOf(CarePlan::class)
        ->and($record->exists)->toBeTrue()
        ->and($record->tenant_id)->toBe('team-1')
        ->and($record->name)->toBe('Sample record')
        ->and($record->status)->toBe('draft')
        ->and(CarePlan::query()->forTenant('team-1')->count())->toBe(1);
});

it('transitions care plans across allowed lifecycle states and rejects invalid transitions', function (): void {
    Event::fake([CarePlanTransitioned::class]);

    $record = (new CreateCarePlan)->execute([
        'tenant_id' => 'team-1',
        'idempotency_key' => 'transition-1',
        'name' => 'Plan A',
        'status' => LifecycleStatus::Draft->value,
        'metadata' => [],
    ]);

    $transition = new TransitionCarePlan;
    $active = $transition->execute($record, LifecycleStatus::Active);
    expect($active->status)->toBe(LifecycleStatus::Active->value);

    $completed = $transition->execute($active, LifecycleStatus::Completed);
    expect($completed->status)->toBe(LifecycleStatus::Completed->value);
    expect(LifecycleStatus::Completed->allowedTransitions())->toBe([]);
    expect(LifecycleStatus::Archived->allowedTransitions())->toBe([]);

    expect(fn () => $transition->execute($completed, LifecycleStatus::Active))
        ->toThrow(InvalidLifecycleTransition::class);

    Event::assertDispatched(CarePlanTransitioned::class, 2);
});
