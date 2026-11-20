<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Liberu\PackageTestbench\UsesTestUser;
use Tests\ModulePackageTestCase;

abstract class LocalizationLivewireFeatureTestCase extends ModulePackageTestCase
{
    use UsesTestUser;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.supported_locales', [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
        ]);
    }
}
