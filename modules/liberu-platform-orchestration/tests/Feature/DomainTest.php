<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Liberu\Platform\PlatformOrchestration\Actions\CreatePlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Actions\TransitionPlatformWorkflow;
use Liberu\Platform\PlatformOrchestration\Enums\LifecycleStatus;
use Liberu\Platform\PlatformOrchestration\Events\PlatformWorkflowTransitioned;
use Liberu\Platform\PlatformOrchestration\Exceptions\InvalidLifecycleTransition;
use Liberu\Platform\PlatformOrchestration\Models\PlatformWorkflow;
use Liberu\PlatformOrchestration\Actions\CreateComposition;
use Liberu\PlatformOrchestration\Actions\TransitionComposition;
use Liberu\PlatformOrchestration\Enums\CompositionState;
use Liberu\PlatformOrchestration\Events\CompositionCreated;
use Liberu\PlatformOrchestration\Events\CompositionStateChanged;
use Liberu\PlatformOrchestration\Exceptions\InvalidCompositionTransition;
use Liberu\PlatformOrchestration\Models\PlatformComposition;

foreach ([
    __DIR__.'/../../src/Enums/CompositionState.php',
    __DIR__.'/../../src/Exceptions/InvalidCompositionTransition.php',
    __DIR__.'/../../src/Models/PlatformComposition.php',
    __DIR__.'/../../src/Events/CompositionCreated.php',
    __DIR__.'/../../src/Events/CompositionStateChanged.php',
    __DIR__.'/../../src/Actions/CreateComposition.php',
    __DIR__.'/../../src/Actions/TransitionComposition.php',
] as $legacyFile) {
    require_once $legacyFile;
}

it('creates and hydrates its aggregate through the domain action', function (): void {
    $record = (new CreatePlatformWorkflow)->execute([
        'tenant_id' => 'team-1',
        'idempotency_key' => 'request-1',
        'name' => 'Sample record',
        'status' => 'draft',
        'metadata' => ['source' => 'archive'],
    ]);

    expect($record)->toBeInstanceOf(PlatformWorkflow::class)
        ->and($record->exists)->toBeTrue()
        ->and($record->tenant_id)->toBe('team-1')
        ->and($record->name)->toBe('Sample record')
        ->and($record->status)->toBe('draft')
        ->and(PlatformWorkflow::query()->forTenant('team-1')->count())->toBe(1);
});

it('transitions platform workflows across allowed lifecycle states and rejects invalid transitions', function (): void {
    Event::fake([PlatformWorkflowTransitioned::class]);

    $record = (new CreatePlatformWorkflow)->execute([
        'tenant_id' => 'team-1',
        'idempotency_key' => 'transition-1',
        'name' => 'Workflow A',
        'status' => LifecycleStatus::Draft->value,
        'metadata' => [],
    ]);

    $transition = new TransitionPlatformWorkflow;
    $active = $transition->execute($record, LifecycleStatus::Active);
    expect($active->status)->toBe(LifecycleStatus::Active->value);

    $completed = $transition->execute($active, LifecycleStatus::Completed);
    expect($completed->status)->toBe(LifecycleStatus::Completed->value);
    expect(LifecycleStatus::Completed->allowedTransitions())->toBe([]);

    expect(fn () => $transition->execute($completed, LifecycleStatus::Active))
        ->toThrow(InvalidLifecycleTransition::class);

    Event::assertDispatched(PlatformWorkflowTransitioned::class, 2);
});

it('creates and transitions platform compositions, validates manifests, and scopes by team', function (): void {
    Event::fake([CompositionCreated::class, CompositionStateChanged::class]);

    $create = new CreateComposition(app(Dispatcher::class));

    expect(fn () => $create->handle([
        'key' => 'bad-1', 'display_name' => 'Bad', 'application' => 'erp',
        'manifest' => ['applications' => [], 'modules' => []],
    ]))->toThrow(ValidationException::class);

    expect(fn () => $create->handle([
        'key' => 'bad-2', 'display_name' => 'Bad', 'application' => 'erp',
        'manifest' => ['applications' => ['erp'], 'modules' => ['']],
    ]))->toThrow(ValidationException::class);

    $composition = $create->handle([
        'team_id' => null,
        'key' => 'erp-default',
        'display_name' => 'ERP Default',
        'application' => 'accounting-erp',
        'manifest' => ['applications' => ['accounting-erp'], 'modules' => ['accounting-core']],
    ]);

    expect($composition->state)->toBe(CompositionState::Draft)
        ->and(PlatformComposition::query()->forTeam(null)->count())->toBe(1)
        ->and(PlatformComposition::query()->forTeam(7)->count())->toBe(1);

    Event::assertDispatched(CompositionCreated::class);

    $transition = new TransitionComposition(app(Dispatcher::class));
    $installed = $transition->handle($composition, CompositionState::Installed);
    expect($installed->state)->toBe(CompositionState::Installed);
    Event::assertDispatched(CompositionStateChanged::class);

    expect(fn () => $transition->handle($installed, CompositionState::Active))
        ->toThrow(InvalidCompositionTransition::class);
});
