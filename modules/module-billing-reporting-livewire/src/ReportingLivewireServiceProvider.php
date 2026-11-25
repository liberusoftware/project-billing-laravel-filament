<?php

declare(strict_types=1);

namespace Liberu\Billing\Reporting\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Reporting\Livewire\Components\CustomerBillingSummary;
use Liberu\Billing\Reporting\Livewire\Components\ReportingDashboard;
use Liberu\Billing\Reporting\Livewire\Components\ReportingMetrics;
use Liberu\Billing\Reporting\Livewire\Components\ReportingSnapshots;
use Livewire\Livewire;

final class ReportingLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-reporting-livewire');
        $components = [
            'module-billing-reporting::metrics' => ReportingMetrics::class,
            'module-billing-reporting::dashboard' => ReportingDashboard::class,
            'module-billing-reporting::snapshots' => ReportingSnapshots::class,
            'module-billing-reporting::customer-summary' => CustomerBillingSummary::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
