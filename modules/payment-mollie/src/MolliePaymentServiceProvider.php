<?php

declare(strict_types=1);

namespace Liberu\Payment\Mollie;

use Illuminate\Support\ServiceProvider;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;

final class MolliePaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MolliePaymentAdapter::class);
    }

    public function boot(): void
    {
        if ($this->app->bound(PaymentGatewayRegistryContract::class)) {
            $this->app->make(PaymentGatewayRegistryContract::class)->register(
                $this->app->make(MolliePaymentAdapter::class),
            );
        }
    }
}
