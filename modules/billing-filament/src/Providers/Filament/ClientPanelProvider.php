<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Client\Pages\Dashboard;
use App\Filament\Client\Pages\Profile;
use App\Filament\ModulePlugins;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ClientPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        ModulePlugins::discoverApplicationComponents($panel, 'Client', ['Resources', 'Pages']);

        return $panel
            ->id('client')
            ->path('client')
            ->login()
            ->colors(
                [
                    'primary' => Color::Blue,
                ]
            )
            ->pages(
                [
                    Dashboard::class,
                    Profile::class,
                ]
            )
            ->middleware(
                [
                    EncryptCookies::class,
                    AddQueuedCookiesToResponse::class,
                    StartSession::class,
                    AuthenticateSession::class,
                    ShareErrorsFromSession::class,
                    PreventRequestForgery::class,
                    SubstituteBindings::class,
                    DisableBladeIconComponents::class,
                    DispatchServingFilamentEvent::class,
                ]
            )
            ->plugins(app(ModulePlugins::class)->forPanel('client'))
            ->authMiddleware(
                [
                    Authenticate::class,
                ]
            );
    }
}
