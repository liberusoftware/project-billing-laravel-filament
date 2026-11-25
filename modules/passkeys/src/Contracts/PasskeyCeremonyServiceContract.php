<?php

declare(strict_types=1);

namespace Liberu\Passkeys\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface PasskeyCeremonyServiceContract
{
    /** @return array<string, mixed> */
    public function beginRegistration(Authenticatable $actor, string $rpId, string $rpName): array;

    /** @return array<string, mixed> */
    public function beginAssertion(string $rpId, ?Authenticatable $actor = null): array;
}
