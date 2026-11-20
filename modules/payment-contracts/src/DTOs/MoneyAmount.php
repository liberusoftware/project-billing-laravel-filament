<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class MoneyAmount
{
    public string $currency;

    public function __construct(
        public int $amountMinor,
        string $currency,
    ) {
        $normalized = strtoupper(trim($currency));
        if (strlen($normalized) !== 3) {
            throw new \InvalidArgumentException('Currency must be a 3-letter ISO 4217 code.');
        }
        $this->currency = $normalized;
    }
}
