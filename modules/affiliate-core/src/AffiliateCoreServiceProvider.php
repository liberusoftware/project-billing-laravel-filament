<?php

declare(strict_types=1);

namespace Liberu\Affiliate\Core;

use Illuminate\Support\ServiceProvider;
use Liberu\Affiliate\Contracts\Contracts\CommissionCalculatorContract;
use Liberu\Affiliate\Core\Services\CommissionCalculator;

final class AffiliateCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CommissionCalculatorContract::class, CommissionCalculator::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
