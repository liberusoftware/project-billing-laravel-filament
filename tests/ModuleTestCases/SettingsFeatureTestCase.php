<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ModulePackageTestCase;

abstract class SettingsFeatureTestCase extends ModulePackageTestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('settings.migrations_paths', [
            dirname(__DIR__, 2).'/modules/settings/database/settings',
        ]);
    }
}
