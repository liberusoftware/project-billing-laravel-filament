<?php

declare(strict_types=1);

namespace Liberu\Passkeys;

use Illuminate\Support\ServiceProvider;
use Liberu\Passkeys\Contracts\PasskeyCeremonyServiceContract;
use Liberu\Passkeys\Services\WebAuthnCeremonyService;

final class PasskeysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PasskeyCeremonyServiceContract::class, WebAuthnCeremonyService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
