<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\PaymentCaptureResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentRefundResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentVoidResultDto;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\DTOs\VoidPaymentCommand;

interface PaymentCaptureAndRefundContract
{
    public function capture(CapturePaymentCommand $command): PaymentCaptureResultDto;

    public function voidAuthorization(VoidPaymentCommand $command): PaymentVoidResultDto;

    public function refund(RefundPaymentCommand $command): PaymentRefundResultDto;
}
