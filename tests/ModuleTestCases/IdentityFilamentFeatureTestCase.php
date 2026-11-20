<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Liberu\Foundation\IdentityFilament\Tests\Fixtures\RoledUser;
use Liberu\Foundation\IdentityFilament\Tests\Fixtures\TestPanelProvider;
use Liberu\Foundation\RolesPermissions\RolesPermissionsServiceProvider;

abstract class IdentityFilamentFeatureTestCase extends FilamentModulePackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', RoledUser::class);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            ...$this->discoveredProviders(),
            RolesPermissionsServiceProvider::class,
            ...parent::getPackageProviders($app),
            TestPanelProvider::class,
        ]));
    }
}
