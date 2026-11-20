<?php

declare(strict_types=1);

namespace Tests\ModuleTestCases;

use Livewire\LivewireServiceProvider;
use Tests\ModulePackageTestCase;

abstract class ModuleManagerUnitTestCase extends ModulePackageTestCase
{
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            LivewireServiceProvider::class,
            ...parent::getPackageProviders($app),
        ]));
    }
}
