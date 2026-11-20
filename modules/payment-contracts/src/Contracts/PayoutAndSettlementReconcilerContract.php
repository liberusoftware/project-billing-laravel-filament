<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\SettlementQueryDto;
use Liberu\Payment\Contracts\DTOs\SettlementTransactionDto;

interface PayoutAndSettlementReconcilerContract
{
    /** @return iterable<int, SettlementTransactionDto> */
    public function listSettlementTransactions(SettlementQueryDto $query): iterable;
}
