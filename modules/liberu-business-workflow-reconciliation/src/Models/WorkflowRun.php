<?php

declare(strict_types=1);

namespace Liberu\BusinessWorkflowReconciliation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\BusinessWorkflowReconciliation\Enums\WorkflowRunState;

/**
 * @property int $id
 * @property int|null $team_id
 * @property string $correlation_id
 * @property string $workflow_key
 * @property string $contract_version
 * @property WorkflowRunState $state
 * @property array<string, mixed> $steps
 * @property string|null $last_error
 */
final class WorkflowRun extends Model
{
    protected $table = 'liberu_workflow_runs';

    protected $fillable = ['team_id', 'correlation_id', 'workflow_key', 'contract_version', 'state', 'steps', 'last_error'];

    protected function casts(): array
    {
        return ['state' => WorkflowRunState::class, 'steps' => 'array'];
    }

    public function scopeForTeam(Builder $query, ?int $teamId): Builder
    {
        return $query->where(function (Builder $query) use ($teamId): void {
            $query->whereNull('team_id');
            if ($teamId !== null) {
                $query->orWhere('team_id', $teamId);
            }
        });
    }
}
