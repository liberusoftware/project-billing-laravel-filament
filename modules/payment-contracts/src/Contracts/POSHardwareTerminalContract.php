<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\CreateTerminalPaymentCommand;
use Liberu\Payment\Contracts\DTOs\TerminalPaymentResultDto;

interface POSHardwareTerminalContract
{
    public function createTerminalPayment(CreateTerminalPaymentCommand $command): TerminalPaymentResultDto;
}
