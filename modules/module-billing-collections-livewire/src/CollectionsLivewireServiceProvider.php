<?php

declare(strict_types=1);

namespace Liberu\Billing\Collections\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Collections\Livewire\Components\CollectionCaseList;
use Livewire\Livewire;

final class CollectionsLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-collections-livewire');
        $components = [
            'module-billing-collections::case-list' => CollectionCaseList::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
