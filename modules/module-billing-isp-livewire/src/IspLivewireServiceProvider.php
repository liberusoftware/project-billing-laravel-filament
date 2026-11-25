<?php

declare(strict_types=1);

namespace Liberu\Billing\Isp\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Isp\Livewire\Components\AccessServices;
use Liberu\Billing\Isp\Livewire\Components\IspCapabilities;
use Livewire\Livewire;

final class IspLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-isp-livewire');
        $components = [
            'module-billing-isp::capabilities' => IspCapabilities::class,
            'module-billing-isp::access-services' => AccessServices::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
