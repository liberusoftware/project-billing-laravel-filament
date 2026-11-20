<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class BeginPaymentMethodSetupCommand
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public MerchantAccountContextDto $account,
        public string $customerReference,
        public string $idempotencyKey,
        public ?string $returnUrl = null,
        public array $metadata = [],
    ) {}
}
