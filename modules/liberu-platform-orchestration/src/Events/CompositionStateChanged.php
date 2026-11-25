<?php

declare(strict_types=1);

namespace Liberu\PlatformOrchestration\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Liberu\PlatformOrchestration\Enums\CompositionState;
use Liberu\PlatformOrchestration\Models\PlatformComposition;

final class CompositionStateChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PlatformComposition $composition,
        public readonly CompositionState $from,
        public readonly CompositionState $to,
    ) {}
}
