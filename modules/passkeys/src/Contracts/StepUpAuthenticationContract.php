<?php

declare(strict_types=1);

namespace Liberu\Passkeys\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface StepUpAuthenticationContract
{
    public function hasRecentStepUp(Authenticatable $actor, string $scope, int $maxAgeSeconds = 300): bool;

    public function recordStepUp(Authenticatable $actor, string $scope): void;
}
