<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class PaymentCapabilityDescriptorDto
{
    /**
     * @param list<string> $supportedMethods
     * @param list<string> $supportedCurrencies
     */
    public function __construct(
        public string $providerKey,
        public string $displayName,
        public array $supportedMethods = [],
        public array $supportedCurrencies = ['USD', 'EUR', 'GBP'],
        public bool $supportsAuthorizeOnly = true,
        public bool $supportsPartialCapture = true,
        public bool $supportsPartialRefund = true,
        public bool $supportsVaulting = true,
        public bool $supportsMandates = false,
        public bool $supportsDisputes = true,
        public bool $supportsSettlementReconciliation = true,
        public bool $supportsPosTerminal = false,
        public bool $isMerchantOfRecord = false,
    ) {}
}
