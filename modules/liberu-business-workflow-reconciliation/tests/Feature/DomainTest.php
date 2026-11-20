<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Liberu\BusinessWorkflowReconciliation\Actions\StartWorkflowRun;
use Liberu\BusinessWorkflowReconciliation\Enums\WorkflowRunState;
use Liberu\BusinessWorkflowReconciliation\Events\WorkflowRunStarted;
use Liberu\BusinessWorkflowReconciliation\Models\WorkflowRun;
use Liberu\Platform\BusinessWorkflowReconciliation\Actions\CreateReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Actions\TransitionReconciliationCase;
use Liberu\Platform\BusinessWorkflowReconciliation\Enums\LifecycleStatus;
use Liberu\Platform\BusinessWorkflowReconciliation\Events\ReconciliationCaseTransitioned;
use Liberu\Platform\BusinessWorkflowReconciliation\Exceptions\InvalidLifecycleTransition;
use Liberu\Platform\BusinessWorkflowReconciliation\Models\ReconciliationCase;

foreach ([
    __DIR__.'/../../src/Enums/WorkflowRunState.php',
    __DIR__.'/../../src/Models/WorkflowRun.php',
    __DIR__.'/../../src/Events/WorkflowRunStarted.php',
    __DIR__.'/../../src/Actions/StartWorkflowRun.php',
] as $legacyFile) {
    require_once $legacyFile;
}

it('creates and hydrates its aggregate through the domain action', function (): void {
    $record = (new CreateReconciliationCase)->execute([
        'tenant_id' => 'team-1',
        'idempotency_key' => 'request-1',
        'name' => 'Sample record',
        'status' => 'draft',
        'metadata' => ['source' => 'archive'],
    ]);

    expect($record)->toBeInstanceOf(ReconciliationCase::class)
        ->and($record->exists)->toBeTrue()
        ->and($record->tenant_id)->toBe('team-1')
        ->and($record->name)->toBe('Sample record')
        ->and($record->status)->toBe('draft')
        ->and(ReconciliationCase::query()->forTenant('team-1')->count())->toBe(1);
});

it('transitions reconciliation cases across allowed lifecycle states and rejects invalid transitions', function (): void {
    Event::fake([ReconciliationCaseTransitioned::class]);

    $record = (new CreateReconciliationCase)->execute([
        'tenant_id' => 'team-1',
        'idempotency_key' => 'transition-1',
        'name' => 'Case A',
        'status' => LifecycleStatus::Draft->value,
        'metadata' => [],
    ]);

    $transition = new TransitionReconciliationCase;
    $active = $transition->execute($record, LifecycleStatus::Active);
    expect($active->status)->toBe(LifecycleStatus::Active->value);

    $completed = $transition->execute($active, LifecycleStatus::Completed);
    expect($completed->status)->toBe(LifecycleStatus::Completed->value);
    expect(LifecycleStatus::Completed->allowedTransitions())->toBe([]);
    expect(LifecycleStatus::Archived->allowedTransitions())->toBe([]);

    expect(fn () => $transition->execute($completed, LifecycleStatus::Active))
        ->toThrow(InvalidLifecycleTransition::class);

    Event::assertDispatched(ReconciliationCaseTransitioned::class, 2);
});

it('starts workflow runs, enforces unique correlation id, and scopes by team', function (): void {
    Event::fake([WorkflowRunStarted::class]);

    $action = new StartWorkflowRun(app(Dispatcher::class));
    $run = $action->handle([
        'team_id' => null,
        'correlation_id' => '00000000-0000-4000-8000-000000000001',
        'workflow_key' => 'close-month',
        'contract_version' => '1.0',
    ]);

    expect($run->state)->toBe(WorkflowRunState::Running)
        ->and($run->steps)->toBe([])
        ->and(WorkflowRun::query()->forTeam(null)->count())->toBe(1)
        ->and(WorkflowRun::query()->forTeam(42)->count())->toBe(1);

    Event::assertDispatched(WorkflowRunStarted::class);

    expect(fn () => $action->handle([
        'correlation_id' => '00000000-0000-4000-8000-000000000001',
        'workflow_key' => 'close-month',
        'contract_version' => '1.0',
    ]))->toThrow(ValidationException::class);
});
