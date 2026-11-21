<?php

declare(strict_types=1);

namespace Liberu\Billing\Usage\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Usage\Livewire\Components\MeterList;
use Liberu\Billing\Usage\Livewire\Components\UsageDashboard;
use Liberu\Billing\Usage\Livewire\Components\UsageRecords;
use Livewire\Livewire;

final class UsageLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-usage-livewire');
        $components = [
            'module-billing-usage::dashboard' => UsageDashboard::class,
            'module-billing-usage::meter-list' => MeterList::class,
            'module-billing-usage::records' => UsageRecords::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        // Livewire 4 resolves namespaced aliases before its explicit component map.
        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
