<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Filament\Facades\Filament;
use Liberu\PackageTestbench\UsesTestUser;
use Tests\ModulePackageTestCase;

abstract class FilamentModulePackageTestCase extends ModulePackageTestCase
{
    use UsesTestUser;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    /**
     * @return array<int, class-string>
     */
    protected function discoveredProviders(): array
    {
        $installedPath = dirname(__DIR__, 2).'/vendor/composer/installed.json';
        $installed = json_decode(
            (string) file_get_contents($installedPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $providers = [];
        $allowedPrefixes = [
            'filament/',
            'livewire/',
            'blade-ui-kit/',
            'danharrin/',
            'kirschbaum-development/',
            'openspout/',
            'ryangjchandler/',
            'spatie/laravel-permission',
            'spatie/laravel-settings',
            'spatie/laravel-package-tools',
        ];

        foreach ($installed['packages'] ?? [] as $package) {
            $name = (string) ($package['name'] ?? '');
            $allowed = false;
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                continue;
            }

            foreach ((array) ($package['extra']['laravel']['providers'] ?? []) as $provider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
