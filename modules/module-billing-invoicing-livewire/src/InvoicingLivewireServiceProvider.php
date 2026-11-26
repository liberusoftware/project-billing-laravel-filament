<?php

declare(strict_types=1);

namespace Liberu\Billing\Invoicing\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Invoicing\Livewire\Components\InvoiceList;
use Liberu\Billing\Invoicing\Livewire\Components\InvoiceSchedules;
use Liberu\Billing\Invoicing\Livewire\Components\InvoiceSupportList;
use Liberu\Billing\Invoicing\Livewire\Components\PaymentPlans;
use Livewire\Livewire;

final class InvoicingLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-invoicing-livewire');
        $components = [
            'module-billing-invoicing::invoice-list' => InvoiceList::class,
            'module-billing-invoicing::schedules' => InvoiceSchedules::class,
            'module-billing-invoicing::support-list' => InvoiceSupportList::class,
            'module-billing-invoicing::payment-plans' => PaymentPlans::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
