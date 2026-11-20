<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class PaymentMethodSetupDto
{
    /** @param array<string, mixed> $clientData */
    public function __construct(
        public string $providerKey,
        public string $setupReferenceId,
        public ?string $clientSecret = null,
        public ?string $redirectUrl = null,
        public array $clientData = [],
    ) {}
}
