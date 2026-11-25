<?php

declare(strict_types=1);

namespace Liberu\Payment\GoCardless;

use Illuminate\Support\ServiceProvider;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;

final class GoCardlessPaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoCardlessPaymentAdapter::class);
    }

    public function boot(): void
    {
        if ($this->app->bound(PaymentGatewayRegistryContract::class)) {
            $this->app->make(PaymentGatewayRegistryContract::class)->register(
                $this->app->make(GoCardlessPaymentAdapter::class),
            );
        }
    }
}
