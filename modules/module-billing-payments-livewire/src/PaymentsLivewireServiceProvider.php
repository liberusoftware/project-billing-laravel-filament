<?php

declare(strict_types=1);

namespace Liberu\Billing\Payments\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Payments\Livewire\Components\PaymentList;
use Liberu\Billing\Payments\Livewire\Components\PaymentMethods;
use Liberu\Billing\Payments\Livewire\Components\PaymentOperations;
use Livewire\Livewire;

final class PaymentsLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-payments-livewire');
        $components = [
            'module-billing-payments::payment-list' => PaymentList::class,
            'module-billing-payments::methods' => PaymentMethods::class,
            'module-billing-payments::operations' => PaymentOperations::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        // Livewire 4 resolves namespaced aliases before its explicit component map.
        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
