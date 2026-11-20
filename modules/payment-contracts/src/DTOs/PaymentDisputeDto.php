<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class PaymentDisputeDto
{
    public function __construct(
        public string $providerKey,
        public string $providerDisputeId,
        public string $providerPaymentReference,
        public string $status,
        public string $reason,
        public MoneyAmount $amount,
    ) {}
}
