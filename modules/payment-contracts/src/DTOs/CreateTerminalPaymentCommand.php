<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class CreateTerminalPaymentCommand
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public MerchantAccountContextDto $account,
        public string $readerId,
        public MoneyAmount $amount,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {}
}
