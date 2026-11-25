<?php

declare(strict_types=1);

namespace Liberu\BusinessWorkflowReconciliation\Enums;

enum WorkflowRunState: string
{
    case Running = 'running';
    case Reconciled = 'reconciled';
    case NeedsRecovery = 'needs_recovery';
    case Resolved = 'resolved';
}
