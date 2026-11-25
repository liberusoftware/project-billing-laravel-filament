<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;

final readonly class PaymentCaptureResultDto
{
    public function __construct(
        public string $providerKey,
        public string $providerIntentId,
        public string $providerCaptureId,
        public PaymentLifecycleState $state,
        public MoneyAmount $capturedAmount,
    ) {}
}
