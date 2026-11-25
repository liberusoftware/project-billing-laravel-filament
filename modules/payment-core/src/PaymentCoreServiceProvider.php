<?php

declare(strict_types=1);

namespace Liberu\Payment\Core;

use Illuminate\Support\ServiceProvider;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Core\Services\PaymentGatewayRegistry;
use Liberu\Payment\Core\Services\PaymentOrchestrator;

final class PaymentCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payment-core.php', 'payment-core');
        $this->app->singleton(PaymentGatewayRegistryContract::class, PaymentGatewayRegistry::class);
        $this->app->singleton(PaymentOrchestrator::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
