<?php

declare(strict_types=1);

namespace Liberu\Billing\Subscriptions\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Subscriptions\Livewire\Components\SubscriptionEntitlements;
use Liberu\Billing\Subscriptions\Livewire\Components\SubscriptionList;
use Livewire\Livewire;

final class SubscriptionsLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'module-billing-subscriptions-livewire');
        $components = [
            'module-billing-subscriptions::subscription-list' => SubscriptionList::class,
            'module-billing-subscriptions::entitlements' => SubscriptionEntitlements::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        // Livewire 4 resolves namespaced aliases before its explicit component map.
        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
