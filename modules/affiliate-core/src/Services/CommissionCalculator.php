<?php

declare(strict_types=1);

namespace Liberu\Affiliate\Core\Services;

use Liberu\Affiliate\Contracts\Contracts\CommissionCalculatorContract;

final class CommissionCalculator implements CommissionCalculatorContract
{
    public function calculateMinor(int $netAmountMinor, int $basisPoints, int $flatMinor = 0): int
    {
        if ($netAmountMinor <= 0) {
            return 0;
        }

        return (int) round(($netAmountMinor * $basisPoints) / 10000) + $flatMinor;
    }
}
