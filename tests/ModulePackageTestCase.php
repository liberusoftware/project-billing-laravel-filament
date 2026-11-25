<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use Liberu\PackageTestbench\PackageRoot;
use Liberu\PackageTestbench\PackageTestCase;
use ReflectionClass;

abstract class ModulePackageTestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        Model::reguard();
        Password::$defaultCallback = null;

        parent::setUp();
    }

    public function ignorePackageDiscoveriesFrom(): array
    {
        return ['*'];
    }

    protected function packageRoot(): string
    {
        $file = property_exists(static::class, '__filename') && is_string(static::$__filename)
            ? static::$__filename
            : (new ReflectionClass($this))->getFileName();

        if (is_string($file) && str_contains($file, DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR)) {
            return PackageRoot::locate($file);
        }

        return parent::packageRoot();
    }

    protected function getPackageProviders($app): array
    {
        $root = $this->packageRoot();
        $hostRoot = dirname(__DIR__);
        $composer = PackageRoot::composer($root);
        $providers = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys($composer[$section] ?? []) as $package) {
                $candidates = [
                    $root.'/vendor/'.$package,
                    $hostRoot.'/vendor/'.$package,
                ];

                if (str_contains($package, '/')) {
                    $short = explode('/', $package, 2)[1];
                    $candidates[] = $hostRoot.'/modules/'.$short;
                }

                $dependency = null;
                foreach ($candidates as $candidate) {
                    if (is_dir($candidate) && is_file($candidate.'/composer.json')) {
                        $dependency = $candidate;
                        break;
                    }
                }

                if ($dependency === null) {
                    continue;
                }

                foreach ((array) (PackageRoot::composer($dependency)['extra']['laravel']['providers'] ?? []) as $provider) {
                    $providers[] = $provider;
                }

                if ($section === 'require-dev') {
                    $providers[] = PackageRoot::manifest($dependency)['provider'] ?? null;
                }
            }
        }

        $providers[] = PackageRoot::manifest($root)['provider'] ?? null;

        return array_values(array_unique(array_filter($providers, is_string(...))));
    }
}
