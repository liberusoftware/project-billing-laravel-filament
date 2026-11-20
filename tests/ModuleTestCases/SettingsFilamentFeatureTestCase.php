<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Illuminate\Support\Facades\DB;
use Liberu\Foundation\Settings\Settings\SiteSettings;
use Liberu\Foundation\Settings\SettingsServiceProvider;
use Liberu\Foundation\SettingsFilament\Tests\Fixtures\TestPanelProvider;
use Liberu\Foundation\Theme\Providers\ThemeServiceProvider;
use Liberu\PackageTestbench\TestUser;

abstract class SettingsFilamentFeatureTestCase extends FilamentModulePackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['company_registration_number', 'ico_registration_number', 'vat_number', 'redress_scheme'] as $name) {
            DB::table('settings')->insertOrIgnore([
                'group' => SiteSettings::group(),
                'name' => $name,
                'locked' => false,
                'payload' => json_encode(null, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', TestUser::class);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            ...$this->discoveredProviders(),
            SettingsServiceProvider::class,
            ThemeServiceProvider::class,
            ...parent::getPackageProviders($app),
            TestPanelProvider::class,
        ]));
    }
}
