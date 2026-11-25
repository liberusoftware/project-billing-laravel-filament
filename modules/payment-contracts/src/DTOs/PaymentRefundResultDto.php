<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;

final readonly class PaymentRefundResultDto
{
    public function __construct(
        public string $providerKey,
        public string $providerReference,
        public string $providerRefundId,
        public PaymentLifecycleState $state,
        public MoneyAmount $refundedAmount,
    ) {}
}
