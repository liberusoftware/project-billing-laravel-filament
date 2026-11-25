<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;

final readonly class MerchantWebhookEventDto
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $providerKey,
        public string $providerEventId,
        public string $eventType,
        public ?string $providerReference = null,
        public ?PaymentLifecycleState $targetState = null,
        public ?MoneyAmount $amount = null,
        public array $payload = [],
    ) {}
}
