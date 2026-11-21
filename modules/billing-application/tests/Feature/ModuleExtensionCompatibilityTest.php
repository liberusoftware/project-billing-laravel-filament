<?php

declare(strict_types=1);

use App\Models\Module;
use App\Modules\BlogModule\BlogModule;
use App\Modules\Events\ModuleDisabled;
use App\Modules\Events\ModuleEnabled;
use App\Modules\Events\ModuleInstalled;
use App\Modules\Events\ModuleUninstalled;
use App\Modules\ModuleManager;
use App\Modules\ModuleServiceProvider;
use App\Modules\Support\ExternalModuleLoader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

it('discovers the existing extension and dispatches its lifecycle events', function (): void {
    $manager = app(ModuleManager::class);
    $extension = $manager->find('BlogModule');
    expect($extension)->toBeInstanceOf(BlogModule::class)
        ->and($manager->has('BlogModule'))->toBeTrue()
        ->and($manager->disabled())->toContain($extension);

    Event::fake([ModuleInstalled::class, ModuleEnabled::class, ModuleDisabled::class, ModuleUninstalled::class]);

    expect($manager->install('BlogModule'))->toBeTrue()
        ->and($manager->enabled())->toContain($extension);
    Event::assertDispatched(ModuleInstalled::class, fn (ModuleInstalled $event) => $event->module === $extension);
    Event::assertDispatched(ModuleEnabled::class, fn (ModuleEnabled $event) => $event->name === 'BlogModule');

    expect($manager->uninstall('BlogModule'))->toBeTrue()
        ->and($manager->disabled())->toContain($extension);
    Event::assertDispatched(ModuleUninstalled::class, fn (ModuleUninstalled $event) => $event->module === $extension);
    Event::assertDispatched(ModuleDisabled::class, fn (ModuleDisabled $event) => $event->name === 'BlogModule');
});

it('keeps extension configuration scoped and visible through the Laravel repository', function (): void {
    $extension = new BlogModule();
    expect($extension->config('per_page', 20))->toBe(20)
        ->and($extension->hasConfig('per_page'))->toBeFalse();

    $extension->setConfig('per_page', 50);
    $extension->mergeConfig(['per_page' => 75, 'moderation' => true]);

    expect($extension->hasConfig('per_page'))->toBeTrue()
        ->and($extension->getAllConfig())->toBe(['per_page' => 75, 'moderation' => true])
        ->and(config('blogmodule.per_page'))->toBe(75);
});

it('executes extension hooks in priority order and can clear a registered hook', function (): void {
    $extension = new BlogModule();
    $calls = [];
    $extension->registerHook('import', function (string $value) use (&$calls): string {
        $calls[] = 'last '.$value;

        return 'finished';
    }, 20);
    $extension->registerHook('import', function (string $value) use (&$calls): void {
        $calls[] = 'first '.$value;
    }, 5);

    expect($extension->executeHook('import', 'post'))->toBe('finished')
        ->and($calls)->toBe(['first post', 'last post'])
        ->and($extension->getHooks())->toBe(['import'])
        ->and($extension->hasHook('import'))->toBeTrue();

    $extension->clearHook('import');

    expect($extension->hasHook('import'))->toBeFalse()
        ->and($extension->executeHook('import', 'post'))->toBeNull();
});

it('loads configured extension paths once and skips missing paths', function (): void {
    $path = base_path('app/Modules');
    config()->set('modules.external_paths', [$path, $path, $path.'/missing']);

    $loader = new ExternalModuleLoader();
    $extensions = $loader->load();

    expect($extensions)->toHaveCount(1)
        ->and($extensions[0])->toBeInstanceOf(BlogModule::class)
        ->and($loader->load())->toBe([]);
});

it('registers extension assets, migration paths and enabled views', function (): void {
    $extensions = Storage::fake('compatibility-extensions');
    $extensions->put('EnabledExtension/config/options.php', "<?php return ['enabled' => true];");
    $extensions->put('EnabledExtension/resources/views/sample.blade.php', 'Enabled extension view');
    $extensions->put('EnabledExtension/resources/lang/en/messages.php', "<?php return ['ready' => 'Extension ready'];");
    $extensions->put('EnabledExtension/resources/assets/example.css', '');
    $extensions->makeDirectory('EnabledExtension/database/migrations');
    $extensions->put('DisabledExtension/resources/views/sample.blade.php', 'Disabled extension view');
    $extensions->makeDirectory('DisabledExtension/database/migrations');

    config()->set('modules.path', $extensions->path(''));
    Module::query()->create(['name' => 'DisabledExtension', 'enabled' => false]);
    app()->register(ModuleServiceProvider::class);

    expect(config('enabled_extension.options.enabled'))->toBeTrue()
        ->and(view('enabled_extension::sample')->render())->toBe('Enabled extension view')
        ->and(__('enabled_extension::messages.ready'))->toBe('Extension ready')
        ->and(view()->exists('disabled_extension::sample'))->toBeFalse()
        ->and(app('migrator')->paths())->toContain($extensions->path('DisabledExtension/database/migrations'))
        ->and(ModuleServiceProvider::pathsToPublish(ModuleServiceProvider::class, 'enabled_extension-assets'))
        ->toHaveKey($extensions->path('EnabledExtension/resources/assets'));
});

it('discovers extensions from installed Composer package metadata when enabled', function (): void {
    $packages = Storage::fake('compatibility-composer');
    $application = app();
    $originalPath = base_path();
    $originalAppPath = app_path();
    config()->set('modules.load_composer_modules', true);

    $packages->makeDirectory('vendor/fixtures/BlogModule');
    $packages->makeDirectory('vendor/fixtures/NotAnExtension');
    $packages->put('vendor/composer/installed.json', json_encode([
        'packages' => [
            ['name' => 'fixtures/regular-package'],
            ['name' => 'fixtures/missing-package', 'extra' => ['laravel-module' => true]],
            ['name' => 'fixtures/NotAnExtension', 'extra' => ['laravel-module' => true]],
            [
                'name' => 'fixtures/BlogModule',
                'extra' => ['laravel-module' => true],
                'autoload' => ['psr-4' => ['App\\Modules\\BlogModule\\' => 'src/']],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $application->setBasePath($packages->path(''));
        $extensions = (new ExternalModuleLoader())->load();

        expect($extensions)->toHaveCount(1)
            ->and($extensions[0])->toBeInstanceOf(BlogModule::class);
        $packages->delete('vendor/composer/installed.json');
        expect((new ExternalModuleLoader())->load())->toBe([]);
    } finally {
        $application->setBasePath($originalPath)->useAppPath($originalAppPath);
    }
});

it('checks dependencies before enabling or disabling extensions', function (): void {
    $manager = app(ModuleManager::class);
    $dependent = new class() extends BlogModule
    {
        protected function loadModuleInfo(): void
        {
            $this->name = 'DependentExtension';
            $this->version = '1.0.0';
            $this->description = 'Depends on the Blog extension.';
            $this->dependencies = ['BlogModule', 'MissingExtension'];
        }
    };
    $manager->register($dependent);

    expect($manager->checkHealth('UnknownExtension')['healthy'])->toBeFalse()
        ->and($manager->checkAllHealth()['DependentExtension'])->toMatchArray([
            'healthy' => false,
            'errors' => ['Missing dependency: MissingExtension'],
            'warnings' => ['Dependency disabled: BlogModule'],
        ])
        ->and(fn () => $manager->enable('DependentExtension'))->toThrow(Exception::class, 'unmet dependencies')
        ->and(fn () => $manager->install('DependentExtension'))->toThrow(Exception::class, 'unmet dependencies');

    Module::query()->create(['name' => 'DependentExtension', 'enabled' => true]);

    expect($manager->checkHealth('DependentExtension')['errors'])
        ->toContain('Module is enabled but has unmet dependencies.')
        ->and(fn () => $manager->disable('BlogModule'))->toThrow(Exception::class, 'other modules depend')
        ->and(fn () => $manager->uninstall('BlogModule'))->toThrow(Exception::class, 'other modules depend');
});
