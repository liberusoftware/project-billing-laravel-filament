<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class ConnectionHealthDto
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $providerKey,
        public bool $healthy,
        public string $message,
        public array $details = [],
    ) {}
}
