<?php

declare(strict_types=1);

namespace Liberu\Billing\Core\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Core\Livewire\Components\BillingAccounts;
use Liberu\Billing\Core\Livewire\Components\BillingCoreRecords;
use Liberu\Billing\Core\Livewire\Components\CurrencyConverter;
use Liberu\Billing\Core\Livewire\Components\TaxCalculator;
use Livewire\Livewire;

final class BillingCoreLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-core-livewire');
        $components = [
            'module-billing-core::billing-accounts' => BillingAccounts::class,
            'module-billing-core::records' => BillingCoreRecords::class,
            'module-billing-core::currency-converter' => CurrencyConverter::class,
            'module-billing-core::tax-calculator' => TaxCalculator::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
