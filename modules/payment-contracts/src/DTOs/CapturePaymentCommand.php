<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class CapturePaymentCommand
{
    public function __construct(
        public MerchantAccountContextDto $account,
        public string $providerIntentId,
        public string $idempotencyKey,
        public ?MoneyAmount $amount = null,
    ) {}
}
