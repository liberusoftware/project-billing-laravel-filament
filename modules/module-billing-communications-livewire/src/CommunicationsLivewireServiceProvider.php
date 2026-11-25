<?php

declare(strict_types=1);

namespace Liberu\Billing\Communications\Livewire;

use Illuminate\Support\ServiceProvider;
use Liberu\Billing\Communications\Livewire\Components\CommunicationInventory;
use Liberu\Billing\Communications\Livewire\Components\CommunicationProviders;
use Liberu\Billing\Communications\Livewire\Components\CommunicationServices;
use Liberu\Billing\Communications\Livewire\Components\VoiceAccounts;
use Livewire\Livewire;

final class CommunicationsLivewireServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'billing-communications-livewire');
        $components = [
            'module-billing-communications::inventory' => CommunicationInventory::class,
            'module-billing-communications::providers' => CommunicationProviders::class,
            'module-billing-communications::services' => CommunicationServices::class,
            'module-billing-communications::voice-accounts' => VoiceAccounts::class,
        ];
        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        Livewire::resolveMissingComponent(static fn (string $name): ?string => $components[$name] ?? null);
    }
}
