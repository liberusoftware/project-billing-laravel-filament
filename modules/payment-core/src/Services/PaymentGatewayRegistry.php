<?php

declare(strict_types=1);

namespace Liberu\Payment\Core\Services;

use Liberu\Payment\Contracts\Contracts\PaymentGatewayAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;

final class PaymentGatewayRegistry implements PaymentGatewayRegistryContract
{
    /** @var array<string, PaymentGatewayAdapterContract> */
    private array $adapters = [];

    public function register(PaymentGatewayAdapterContract $adapter): void
    {
        $key = strtolower(trim($adapter->providerKey()));
        if ($key === '') {
            throw new \InvalidArgumentException('Payment provider key cannot be empty.');
        }
        $this->adapters[$key] = $adapter;
    }

    public function has(string $providerKey): bool
    {
        return isset($this->adapters[strtolower(trim($providerKey))]);
    }

    public function resolve(string $providerKey): PaymentGatewayAdapterContract
    {
        $key = strtolower(trim($providerKey));
        if (! isset($this->adapters[$key])) {
            throw new \InvalidArgumentException("Payment gateway adapter [{$providerKey}] is not registered.");
        }

        return $this->adapters[$key];
    }

    public function all(): array
    {
        return $this->adapters;
    }
}
