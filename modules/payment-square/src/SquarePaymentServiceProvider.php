<?php

declare(strict_types=1);

namespace Liberu\Payment\Square;

use Illuminate\Support\ServiceProvider;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;

final class SquarePaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SquarePaymentAdapter::class);
    }

    public function boot(): void
    {
        if ($this->app->bound(PaymentGatewayRegistryContract::class)) {
            $this->app->make(PaymentGatewayRegistryContract::class)->register(
                $this->app->make(SquarePaymentAdapter::class),
            );
        }
    }
}
