<?php

declare(strict_types=1);

namespace Liberu\BusinessWorkflowReconciliation\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Liberu\BusinessWorkflowReconciliation\Enums\WorkflowRunState;
use Liberu\BusinessWorkflowReconciliation\Events\WorkflowRunStarted;
use Liberu\BusinessWorkflowReconciliation\Models\WorkflowRun;

final class StartWorkflowRun
{
    public function __construct(private readonly Dispatcher $events) {}

    /** @param array{team_id?: int|null, correlation_id: string, workflow_key: string, contract_version: string, steps?: array<string, mixed>} $attributes */
    public function handle(array $attributes): WorkflowRun
    {
        if (WorkflowRun::query()->where('correlation_id', $attributes['correlation_id'])->exists()) {
            throw ValidationException::withMessages(['correlation_id' => 'A workflow run already exists for this correlation ID.']);
        }

        return DB::transaction(function () use ($attributes): WorkflowRun {
            $run = WorkflowRun::query()->create([
                ...$attributes,
                'state' => WorkflowRunState::Running,
                'steps' => $attributes['steps'] ?? [],
            ]);
            $this->events->dispatch(new WorkflowRunStarted($run));

            return $run;
        });
    }
}
