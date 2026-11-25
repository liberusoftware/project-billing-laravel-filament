<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\PaymentCapabilityDescriptorDto;

interface PaymentGatewayAdapterContract
{
    public function providerKey(): string;

    public function descriptor(): PaymentCapabilityDescriptorDto;

    public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto;
}
