<?php

declare(strict_types=1);

namespace Liberu\PlatformOrchestration\Exceptions;

use DomainException;
use Liberu\PlatformOrchestration\Enums\CompositionState;

final class InvalidCompositionTransition extends DomainException
{
    public function __construct(CompositionState $from, CompositionState $to)
    {
        parent::__construct("Composition cannot transition from {$from->value} to {$to->value}.");
    }
}
