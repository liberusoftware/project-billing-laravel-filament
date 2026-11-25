<?php

declare(strict_types=1);

namespace Liberu\Payment\Paddle;

use Illuminate\Support\ServiceProvider;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;

final class PaddlePaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaddlePaymentAdapter::class);
    }

    public function boot(): void
    {
        if ($this->app->bound(PaymentGatewayRegistryContract::class)) {
            $this->app->make(PaymentGatewayRegistryContract::class)->register(
                $this->app->make(PaddlePaymentAdapter::class),
            );
        }
    }
}
