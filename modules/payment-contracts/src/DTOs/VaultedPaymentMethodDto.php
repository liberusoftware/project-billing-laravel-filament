<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class VaultedPaymentMethodDto
{
    /** @param array<string, mixed> $displayDetails */
    public function __construct(
        public string $providerKey,
        public string $providerMethodId,
        public string $methodType,
        public ?string $customerReference = null,
        public ?string $mandateReference = null,
        public array $displayDetails = [],
    ) {}
}
