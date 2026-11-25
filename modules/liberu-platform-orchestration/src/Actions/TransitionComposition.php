<?php

declare(strict_types=1);

namespace Liberu\PlatformOrchestration\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Liberu\PlatformOrchestration\Enums\CompositionState;
use Liberu\PlatformOrchestration\Events\CompositionStateChanged;
use Liberu\PlatformOrchestration\Exceptions\InvalidCompositionTransition;
use Liberu\PlatformOrchestration\Models\PlatformComposition;

final class TransitionComposition
{
    public function __construct(private readonly Dispatcher $events) {}

    public function handle(PlatformComposition $composition, CompositionState $state): PlatformComposition
    {
        if (! $composition->canTransitionTo($state)) {
            throw new InvalidCompositionTransition($composition->state, $state);
        }

        return DB::transaction(function () use ($composition, $state): PlatformComposition {
            $from = $composition->state;
            $composition->update(['state' => $state]);
            $composition->refresh();
            $this->events->dispatch(new CompositionStateChanged($composition, $from, $state));

            return $composition;
        });
    }
}
