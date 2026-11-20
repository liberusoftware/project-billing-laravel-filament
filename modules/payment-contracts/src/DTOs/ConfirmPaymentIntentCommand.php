<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class ConfirmPaymentIntentCommand
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public MerchantAccountContextDto $account,
        public string $providerIntentId,
        public string $idempotencyKey,
        public ?string $paymentMethodToken = null,
        public array $payload = [],
    ) {}
}
