<?php

declare(strict_types=1);

namespace Liberu\Billing\Stripe;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Payments\Services\GatewayManager;
use Stripe\StripeClient;

final class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, fn (): StripeClient => new StripeClient([
            'api_key' => (string) config('services.stripe.secret'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
    }

    public function boot(GatewayManager $gateways): void
    {
        $gateways->register('stripe', $this->app->make(StripeGatewayDriver::class));
        $gateways->register('Stripe', $this->app->make(StripeGatewayDriver::class));
    }
}
