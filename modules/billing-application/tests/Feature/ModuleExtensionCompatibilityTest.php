<?php

declare(strict_types=1);

use App\Models\Module;
use App\Modules\BaseModule;
use App\Modules\BlogModule\BlogModule;
use App\Modules\Events\ModuleDisabled;
use App\Modules\Events\ModuleEnabled;
use App\Modules\Events\ModuleInstalled;
use App\Modules\Events\ModuleUninstalled;
use App\Modules\ModuleManager;
use App\Modules\ModuleServiceProvider;
use App\Modules\Support\ExternalModuleLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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

it('reuses the configured extension cache until it is cleared', function (): void {
    config()->set('modules.cache', true);
    config()->set('modules.cache_key', 'billing.extension.compatibility');

    $firstDiscovery = new ModuleManager();
    expect($firstDiscovery->has('BlogModule'))->toBeTrue()
        ->and($firstDiscovery->getAllModulesInfo())->toHaveKey('BlogModule');

    config()->set('modules.path', base_path('app/Modules/missing'));
    expect((new ModuleManager())->has('BlogModule'))->toBeTrue();

    $firstDiscovery->clearCache();

    expect((new ModuleManager())->has('BlogModule'))->toBeFalse();
});

it('keeps extension migration and asset commands on their original paths', function (): void {
    $workspace = Storage::fake('compatibility-module-workspace');
    $workspace->put('app/Modules/FixtureExtension/module.json', '{"name":"FixtureExtension"}');
    $workspace->makeDirectory('app/Modules/FixtureExtension/database/migrations');
    $workspace->put('public/modules/FixtureExtension/fixture.css', 'body {}');
    $extension = new class($workspace->path('app/Modules/FixtureExtension')) extends BaseModule
    {
        public function __construct(private readonly string $extensionPath)
        {
            parent::__construct();
        }

        protected function getModulePath(): string
        {
            return $this->extensionPath;
        }
    };
    $application = app();
    $originalPath = base_path();
    $originalAppPath = app_path();
    $migrationOptions = ['--path' => 'app/Modules/FixtureExtension/database/migrations', '--force' => true];

    Artisan::shouldReceive('call')->once()->with('migrate', $migrationOptions);
    Artisan::shouldReceive('call')->once()->with('vendor:publish', ['--tag' => 'fixtureextension-assets', '--force' => true]);
    Artisan::shouldReceive('call')->once()->with('migrate:rollback', $migrationOptions)
        ->andThrow(new RuntimeException('Migration rollback unavailable'));

    try {
        $application->setBasePath($workspace->path(''));
        $extension->install();
        Module::query()->create(['name' => 'FixtureExtension', 'enabled' => true]);
        $extension->uninstall();

        expect($workspace->exists('public/modules/FixtureExtension/fixture.css'))->toBeFalse();
    } finally {
        $application->setBasePath($originalPath)->useAppPath($originalAppPath);
    }
});

it('keeps already enabled extensions from rerunning their enable hooks', function (): void {
    $manager = app(ModuleManager::class);
    expect($manager->enable('BlogModule'))->toBeTrue();

    $extension = $manager->get('BlogModule');
    $extension->registerHook('before_enable', fn () => throw new LogicException('Enable hook reran'));
    Event::fake([ModuleEnabled::class]);

    $extension->enable();

    Event::assertNotDispatched(ModuleEnabled::class);
    expect($extension->isEnabled())->toBeTrue();
});

it('preserves the cause of a failed enable hook', function (): void {
    $extension = new class() extends BlogModule
    {
        protected function loadModuleInfo(): void
        {
            $this->name = 'FailingExtension';
        }

        protected function onEnable(): void
        {
            throw new LogicException('Extension setup failed');
        }
    };
    Event::fake([ModuleEnabled::class]);

    expect(fn () => $extension->enable())->toThrow(
        fn (RuntimeException $exception) => expect($exception->getPrevious())->toBeInstanceOf(LogicException::class),
    );
    Event::assertNotDispatched(ModuleEnabled::class);
});

it('continues uninstall when extension cleanup or listeners throw', function (): void {
    $extension = new class() extends BlogModule
    {
        protected function loadModuleInfo(): void
        {
            $this->name = 'FailingCleanup';
            $this->config = ['enabled' => true];
        }

        protected function onDisable(): void
        {
            throw new LogicException('Extension cleanup failed');
        }
    };
    $completed = false;
    $extension->registerHook('after_uninstall', function () use (&$completed): void {
        $completed = true;
    });
    foreach ([ModuleDisabled::class, ModuleUninstalled::class] as $event) {
        Event::listen($event, fn () => throw new LogicException('Listener failed'));
    }

    $extension->uninstall();

    expect($completed)->toBeTrue();
});

it('finishes installation when asset publication or lifecycle listeners fail', function (): void {
    $extension = new BlogModule();
    Artisan::shouldReceive('call')->once()->with('vendor:publish', [
        '--tag' => 'blogmodule-assets',
        '--force' => true,
    ])->andThrow(new RuntimeException('Assets are unavailable'));

    $events = [];
    foreach ([ModuleEnabled::class, ModuleInstalled::class] as $event) {
        Event::listen($event, function (object $message) use (&$events): never {
            $events[] = $message::class;
            throw new LogicException('Listener failed');
        });
    }

    $extension->install();

    expect($events)->toBe([ModuleEnabled::class, ModuleInstalled::class]);
});

it('rejects an extension migration directory outside its expected location', function (): void {
    $workspace = Storage::fake('compatibility-invalid-location');
    $workspace->put('module.json', '{"name":"MisplacedExtension"}');
    $workspace->makeDirectory('database/migrations');
    $extension = new class($workspace->path('')) extends BaseModule
    {
        public function __construct(private readonly string $extensionPath)
        {
            parent::__construct();
        }

        protected function getModulePath(): string
        {
            return $this->extensionPath;
        }
    };

    Artisan::shouldReceive('call')->never();

    expect(fn () => $extension->install())->toThrow(RuntimeException::class, 'Invalid module path');
});

it('registers routes and migrations from the source-layout extension', function (): void {
    $extensions = Storage::fake('compatibility-source-extension');
    $extensions->put('SourceExtension/src/routes/web.php', <<<'ROUTE'
        <?php

        Illuminate\Support\Facades\Route::get('/extension-compatibility', fn () => 'Extension route')
            ->name('extension.compatibility');
        ROUTE);
    $extensions->makeDirectory('SourceExtension/src/database/migrations');
    foreach (['Resources', 'Pages', 'Widgets'] as $componentDirectory) {
        $extensions->makeDirectory('SourceExtension/src/Filament/'.$componentDirectory);
    }
    config()->set('modules.path', $extensions->path(''));

    app()->register(ModuleServiceProvider::class);

    $this->get('/extension-compatibility')->assertOk()->assertSee('Extension route');
    expect(app('migrator')->paths())->toContain($extensions->path('SourceExtension/src/database/migrations'));
});

it('discovers external extensions when the primary extension directory is unavailable', function (): void {
    config()->set('modules.path', base_path('app/Modules/missing'));
    config()->set('modules.external_paths', [base_path('app/Modules')]);

    $manager = new ModuleManager();

    expect($manager->all())->toHaveCount(1)
        ->and($manager->get('BlogModule'))->toBeInstanceOf(BlogModule::class)
        ->and($manager->install('UnknownExtension'))->toBeFalse()
        ->and($manager->uninstall('UnknownExtension'))->toBeFalse()
        ->and($manager->getModuleInfo('UnknownExtension'))->toBe([]);
});

it('still runs extension hooks while the module state table is unavailable', function (): void {
    $manager = app(ModuleManager::class);
    $extension = $manager->get('BlogModule');
    $events = [ModuleEnabled::class, ModuleInstalled::class, ModuleUninstalled::class];
    Event::fake($events);
    Schema::drop('modules');
    app()->register(ModuleServiceProvider::class);

    expect($extension->isEnabled())->toBeFalse()
        ->and($manager->enable('BlogModule'))->toBeTrue()
        ->and($manager->disable('BlogModule'))->toBeTrue()
        ->and($manager->install('BlogModule'))->toBeTrue()
        ->and($manager->uninstall('BlogModule'))->toBeTrue();

    Event::assertDispatchedTimes(ModuleEnabled::class, 2);
    Event::assertDispatchedTimes(ModuleInstalled::class, 1);
    Event::assertDispatchedTimes(ModuleUninstalled::class, 1);
});

it('rejects invalid extension names before invoking migration commands', function (): void {
    $extension = new class() extends BaseModule
    {
        protected function loadModuleInfo(): void
        {
            $this->name = 'Invalid Module';
        }
    };
    Artisan::shouldReceive('call')->never();

    expect(fn () => $extension->install())->toThrow(InvalidArgumentException::class, 'Invalid module name: Invalid Module');
    $extension->uninstall();
});

it('registers providers declared in a deployed source-layout extension', function (): void {
    $workspace = Storage::fake('compatibility-deployed-extension');
    $workspace->put('app-modules/DeployedExtension/src/DeployedExtension.php', <<<'MODULE'
        <?php

        namespace Modules\DeployedExtension;

        class DeployedExtension extends \App\Modules\BaseModule {}
        MODULE);
    $workspace->put('app-modules/DeployedExtension/src/module.json', '{"name":"DeployedExtension"}');
    $workspace->put('app-modules/DeployedExtension/src/Providers/DeployedExtensionServiceProvider.php', <<<'PROVIDER'
        <?php

        namespace Modules\DeployedExtension\Providers;

        class DeployedExtensionServiceProvider extends \Illuminate\Support\ServiceProvider
        {
            public function register(): void
            {
                $this->app->instance('deployed-extension.registration', 'ready');
            }
        }
        PROVIDER);
    $workspace->put('app-modules/UnavailableExtension/UnavailableExtension.php', <<<'MODULE'
        <?php

        namespace Modules\UnavailableExtension;

        class UnavailableExtension extends \App\Modules\BaseModule
        {
            public function __construct()
            {
                throw new \RuntimeException('Environment unavailable');
            }
        }
        MODULE);
    require $workspace->path('app-modules/UnavailableExtension/UnavailableExtension.php');
    require $workspace->path('app-modules/DeployedExtension/src/DeployedExtension.php');
    require $workspace->path('app-modules/DeployedExtension/src/Providers/DeployedExtensionServiceProvider.php');

    $application = app();
    $originalPath = base_path();
    $originalAppPath = app_path();
    config()->set('modules.external_paths', [$originalPath.'/app/Modules']);

    try {
        $application->setBasePath($workspace->path(''));
        $manager = new ModuleManager();
        $application->register(ModuleServiceProvider::class);

        expect($manager->has('DeployedExtension'))->toBeTrue()
            ->and($manager->has('UnavailableExtension'))->toBeFalse()
            ->and($application->make('deployed-extension.registration'))->toBe('ready');
    } finally {
        $application->setBasePath($originalPath)->useAppPath($originalAppPath);
    }
});

it('allows the application to boot when an extension directory cannot be read', function (): void {
    $directory = base_path('app/Modules/unavailable');
    config()->set('modules.path', $directory);
    File::partialMock()->shouldReceive('exists')->with($directory)->twice()
        ->andThrow(new RuntimeException('Extension filesystem unavailable'));

    app()->register(ModuleServiceProvider::class);

    expect(app()->getProvider(ModuleServiceProvider::class))->not->toBeNull();
});
