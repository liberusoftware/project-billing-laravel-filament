<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class SettlementTransactionDto
{
    public function __construct(
        public string $providerKey,
        public string $settlementTransactionId,
        public ?string $providerPaymentReference,
        public MoneyAmount $grossAmount,
        public MoneyAmount $feeAmount,
        public MoneyAmount $netAmount,
        public string $type,
        public string $occurredAtIso8601,
    ) {}
}
