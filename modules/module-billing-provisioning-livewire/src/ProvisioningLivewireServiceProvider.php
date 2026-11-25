<?php

declare(strict_types=1);

namespace Liberu\Billing\Provisioning\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Provisioning\Livewire\Components\ProvisioningOperations;
use Livewire\Livewire;

final class ProvisioningLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-provisioning-livewire');
        $components = [
            'module-billing-provisioning::operations' => ProvisioningOperations::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        // Livewire 4 resolves namespaced aliases before its explicit component map.
        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
