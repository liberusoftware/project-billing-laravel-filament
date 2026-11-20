<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class CreatePaymentIntentCommand
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public MerchantAccountContextDto $account,
        public MoneyAmount $amount,
        public string $idempotencyKey,
        public ?string $paymentMethodToken = null,
        public ?string $customerReference = null,
        public ?string $description = null,
        public ?string $returnUrl = null,
        public bool $captureImmediately = true,
        public array $metadata = [],
    ) {}
}
