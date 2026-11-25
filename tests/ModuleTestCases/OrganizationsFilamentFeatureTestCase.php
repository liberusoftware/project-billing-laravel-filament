<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Organizations\OrganizationsServiceProvider;
use Liberu\Foundation\OrganizationsFilament\Tests\Fixtures\OrganizationUser;
use Liberu\Foundation\OrganizationsFilament\Tests\Fixtures\TestPanelProvider;

abstract class OrganizationsFilamentFeatureTestCase extends FilamentModulePackageTestCase
{
    private string $previousJetstreamUserModel = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousJetstreamUserModel = Jetstream::userModel();
        Jetstream::useUserModel(OrganizationUser::class);
    }

    protected function tearDown(): void
    {
        if ($this->previousJetstreamUserModel !== '') {
            Jetstream::useUserModel($this->previousJetstreamUserModel);
        }

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', OrganizationUser::class);
        $app['config']->set('activitylog.enabled', false);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            ...$this->discoveredProviders(),
            OrganizationsServiceProvider::class,
            ...parent::getPackageProviders($app),
            TestPanelProvider::class,
        ]));
    }
}
