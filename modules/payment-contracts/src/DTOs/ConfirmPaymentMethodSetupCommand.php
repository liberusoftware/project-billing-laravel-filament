<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class ConfirmPaymentMethodSetupCommand
{
    public function __construct(
        public MerchantAccountContextDto $account,
        public string $setupReferenceId,
        public string $idempotencyKey,
    ) {}
}
