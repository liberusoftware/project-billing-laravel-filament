<?php

declare(strict_types=1);

namespace Liberu\Affiliate\Contracts\Contracts;

interface ConversionRecorderContract
{
    /** @param array<string, mixed> $context */
    public function recordConversion(
        string $tenantId,
        string $sourceType,
        string $sourceId,
        int $netAmountMinor,
        string $currency,
        ?string $attributionToken = null,
        ?string $promoCode = null,
        array $context = [],
    ): string;

    public function reverseConversion(
        string $tenantId,
        string $sourceType,
        string $sourceId,
        int $reversedAmountMinor,
        string $reason,
    ): void;
}
