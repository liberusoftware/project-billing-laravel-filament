<?php

declare(strict_types=1);

namespace Liberu\Affiliate\Contracts\Contracts;

interface CommissionCalculatorContract
{
    public function calculateMinor(int $netAmountMinor, int $basisPoints, int $flatMinor = 0): int;
}
