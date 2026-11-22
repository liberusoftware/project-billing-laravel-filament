<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;

interface OutboundPaymentAdapterContract extends PaymentGatewayAdapterContract
{
    /** @param array<string, mixed> $payment */
    public function sendOutboundPayment(MerchantAccountContextDto $account, array $payment): array;

    /** @param list<array<string, mixed>> $payments */
    public function sendOutboundBatch(MerchantAccountContextDto $account, string $title, array $payments, ?string $scheduleFor = null): array;
}
