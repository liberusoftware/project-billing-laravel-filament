<?php

declare(strict_types=1);

namespace Liberu\Affiliate\Contracts\Contracts;

interface PayoutSettlementContract
{
    /** @param list<string> $conversionIds */
    public function createSettlementBatch(string $tenantId, array $conversionIds): string;
}
