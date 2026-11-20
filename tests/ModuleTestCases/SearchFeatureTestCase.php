<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Liberu\Foundation\Search\Tests\Fixtures\SearchableUser;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;
use Tests\ModulePackageTestCase;

abstract class SearchFeatureTestCase extends ModulePackageTestCase
{
    use UsesTestUser;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('search.models.user', SearchableUser::class);
        $app['config']->set('auth.providers.users.model', TestUser::class);
    }
}
