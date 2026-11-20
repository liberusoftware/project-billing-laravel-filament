<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class DetachPaymentMethodCommand
{
    public function __construct(
        public MerchantAccountContextDto $account,
        public string $providerMethodId,
    ) {}
}
