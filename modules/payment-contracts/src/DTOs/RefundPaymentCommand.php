<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class RefundPaymentCommand
{
    public function __construct(
        public MerchantAccountContextDto $account,
        public string $providerReference,
        public MoneyAmount $amount,
        public string $idempotencyKey,
        public string $reason = 'requested_by_customer',
    ) {}
}
