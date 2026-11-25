<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

interface PaymentGatewayRegistryContract
{
    public function register(PaymentGatewayAdapterContract $adapter): void;

    public function has(string $providerKey): bool;

    public function resolve(string $providerKey): PaymentGatewayAdapterContract;

    /** @return array<string, PaymentGatewayAdapterContract> */
    public function all(): array;
}
