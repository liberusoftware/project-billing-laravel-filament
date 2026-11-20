<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use JoelButcher\Socialstream\Socialstream;
use Laravel\Jetstream\Jetstream;
use Liberu\Foundation\Identity\Socialstream\Tests\SocialstreamActor;
use Tests\ModulePackageTestCase;

require_once dirname(__DIR__, 2).'/modules/identity-socialstream/tests/TestCase.php';

abstract class SocialstreamFeatureTestCase extends ModulePackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', SocialstreamActor::class);
        $app['config']->set('activitylog.enabled', false);
        $app['config']->set('socialstream.features', []);
        $app['config']->set('jetstream.features', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Socialstream::useUserModel(SocialstreamActor::class);
        Jetstream::useUserModel(SocialstreamActor::class);
    }
}
