<?php

declare(strict_types=1);

namespace Liberu\PlatformOrchestration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Liberu\PlatformOrchestration\Models\PlatformComposition;

final class CompositionCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PlatformComposition $composition) {}
}
