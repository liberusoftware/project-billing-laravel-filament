<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\CancelPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\PaymentIntentResultDto;

interface PaymentIntentServiceContract
{
    public function createIntent(CreatePaymentIntentCommand $command): PaymentIntentResultDto;

    public function confirmIntent(ConfirmPaymentIntentCommand $command): PaymentIntentResultDto;

    public function cancelIntent(CancelPaymentIntentCommand $command): PaymentIntentResultDto;
}
