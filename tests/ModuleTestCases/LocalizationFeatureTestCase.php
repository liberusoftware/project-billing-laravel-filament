<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Liberu\PackageTestbench\UsesTestUser;
use Tests\ModulePackageTestCase;

abstract class LocalizationFeatureTestCase extends ModulePackageTestCase
{
    use UsesTestUser;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('localization.locales', [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
        ]);
    }
}
