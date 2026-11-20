<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;

final readonly class TerminalPaymentResultDto
{
    public function __construct(
        public string $providerKey,
        public string $readerId,
        public string $providerPaymentId,
        public PaymentLifecycleState $state,
        public MoneyAmount $amount,
    ) {}
}
