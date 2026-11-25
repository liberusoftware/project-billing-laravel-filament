<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class SettlementQueryDto
{
    public function __construct(
        public MerchantAccountContextDto $account,
        public ?string $payoutId = null,
        public ?string $fromIso8601 = null,
        public ?string $toIso8601 = null,
        public int $limit = 100,
    ) {}
}
