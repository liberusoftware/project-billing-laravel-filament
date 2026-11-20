<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class MerchantAccountContextDto
{
    /** @param array<string, mixed> $credentials */
    public function __construct(
        public string $tenantId,
        public string $providerKey,
        public array $credentials = [],
        public bool $sandbox = false,
        public ?string $apiVersion = null,
    ) {}
}
