<?php

declare(strict_types=1);

namespace Liberu\BusinessWorkflowReconciliation\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Liberu\BusinessWorkflowReconciliation\Models\WorkflowRun;

final class WorkflowRunStarted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly WorkflowRun $run) {}
}
