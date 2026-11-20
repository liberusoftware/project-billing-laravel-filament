<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;

final readonly class PaymentIntentResultDto
{
    /** @param array<string, mixed> $clientAction */
    /** @param array<string, mixed> $rawMetadata */
    public function __construct(
        public string $providerKey,
        public string $providerIntentId,
        public PaymentLifecycleState $state,
        public MoneyAmount $amount,
        public ?string $clientSecret = null,
        public ?string $redirectUrl = null,
        public array $clientAction = [],
        public array $rawMetadata = [],
    ) {}
}
