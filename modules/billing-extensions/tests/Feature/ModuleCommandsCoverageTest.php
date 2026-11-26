<?php

declare(strict_types=1);

use App\Modules\BaseModule;
use App\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('validates names and scaffolds standard and modular modules with make:module', function (): void {
    // Invalid name
    $this->artisan('make:module', ['name' => 'invalid_name'])
        ->assertExitCode(1);

    $stdName = 'CovTempStd';
    $stdPath = base_path("modules/billing-extensions/src/{$stdName}");
    File::deleteDirectory($stdPath);

    try {
        // Create standard module
        $this->artisan('make:module', ['name' => $stdName])
            ->assertExitCode(0);

        expect(File::exists("{$stdPath}/{$stdName}Module.php"))->toBeTrue()
            ->and(File::exists("{$stdPath}/module.json"))->toBeTrue();

        // Already exists without --force
        $this->artisan('make:module', ['name' => $stdName])
            ->assertExitCode(1);

        // Overwrite with --force
        $this->artisan('make:module', ['name' => $stdName, '--force' => true])
            ->assertExitCode(0);
    } finally {
        File::deleteDirectory($stdPath);
    }

    $modName = 'CovTempMod';
    $appModulesExisted = File::exists(base_path('app-modules'));
    $modPath = base_path("app-modules/{$modName}");
    File::deleteDirectory($modPath);

    try {
        // Create modular layout module
        $this->artisan('make:module', ['name' => $modName, '--modular' => true])
            ->assertExitCode(0);

        expect(File::exists("{$modPath}/composer.json"))->toBeTrue()
            ->and(File::exists("{$modPath}/src/{$modName}Module.php"))->toBeTrue();
    } finally {
        File::deleteDirectory($modPath);
        if (! $appModulesExisted && File::isDirectory(base_path('app-modules')) && File::directories(base_path('app-modules')) === []) {
            File::deleteDirectory(base_path('app-modules'));
        }
    }
});

it('handles all ModuleCommand actions, formats, confirmations, and error paths', function (): void {
    $enabledMod = new class() extends BaseModule
    {
        public function getName(): string
        {
            return 'Alpha';
        }

        public function getVersion(): string
        {
            return '1.2.0';
        }

        public function getDescription(): string
        {
            return 'Alpha module';
        }

        public function getDependencies(): array
        {
            return ['Core'];
        }

        public function isEnabled(): bool
        {
            return true;
        }
    };

    $disabledMod = new class() extends BaseModule
    {
        public function getName(): string
        {
            return 'Beta';
        }

        public function getVersion(): string
        {
            return '0.9.0';
        }

        public function getDescription(): string
        {
            return 'Beta module';
        }

        public function getDependencies(): array
        {
            return [];
        }

        public function isEnabled(): bool
        {
            return false;
        }
    };

    $manager = Mockery::mock(ModuleManager::class);
    $this->app->instance(ModuleManager::class, $manager);

    // list empty (table & json)
    $manager->shouldReceive('all')->twice()->andReturn(new Collection([]));
    $this->artisan('module', ['action' => 'list'])->assertExitCode(0);
    $this->artisan('module', ['action' => 'list', '--format' => 'json'])->assertExitCode(0);

    // list non-empty (table & json)
    $manager->shouldReceive('all')->twice()->andReturn(new Collection([$enabledMod, $disabledMod]));
    $this->artisan('module', ['action' => 'list'])->assertExitCode(0);
    $this->artisan('module', ['action' => 'list', '--format' => 'json'])->assertExitCode(0);

    // enable: missing name, success, not found, exception
    $this->artisan('module', ['action' => 'enable'])->assertExitCode(1);
    $manager->shouldReceive('enable')->with('Alpha')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'enable', 'name' => 'Alpha'])->assertExitCode(0);
    $manager->shouldReceive('enable')->with('Missing')->once()->andReturn(false);
    $this->artisan('module', ['action' => 'enable', 'name' => 'Missing'])->assertExitCode(1);
    $manager->shouldReceive('enable')->with('Broken')->once()->andThrow(new Exception('Boom'));
    $this->artisan('module', ['action' => 'enable', 'name' => 'Broken'])->assertExitCode(1);

    // disable & install
    $manager->shouldReceive('disable')->with('Alpha')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'disable', 'name' => 'Alpha'])->assertExitCode(0);
    $manager->shouldReceive('install')->with('Alpha')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'install', 'name' => 'Alpha'])->assertExitCode(0);

    // uninstall: missing name, cancelled confirmation, --force
    $this->artisan('module', ['action' => 'uninstall'])->assertExitCode(1);
    $this->artisan('module', ['action' => 'uninstall', 'name' => 'Alpha'])
        ->expectsConfirmation("Uninstall module 'Alpha'? This cannot be undone.", 'no')
        ->assertExitCode(0);
    $manager->shouldReceive('uninstall')->with('Alpha')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'uninstall', 'name' => 'Alpha', '--force' => true])->assertExitCode(0);

    // create: missing name + delegates to make:module
    $this->artisan('module', ['action' => 'create'])->assertExitCode(1);
    $createdPath = base_path('modules/billing-extensions/src/CovCmdCreate');
    File::deleteDirectory($createdPath);
    try {
        $this->artisan('module', ['action' => 'create', 'name' => 'CovCmdCreate', '--force' => true])
            ->assertExitCode(0);
    } finally {
        File::deleteDirectory($createdPath);
    }

    // info: missing name, not found, found (table & json)
    $this->artisan('module', ['action' => 'info'])->assertExitCode(1);
    $manager->shouldReceive('getModuleInfo')->with('Nope')->once()->andReturn([]);
    $this->artisan('module', ['action' => 'info', 'name' => 'Nope'])->assertExitCode(1);
    $manager->shouldReceive('getModuleInfo')->with('Alpha')->twice()->andReturn([
        'name' => 'Alpha',
        'dependencies' => ['Core'],
        'tags' => [],
    ]);
    $this->artisan('module', ['action' => 'info', 'name' => 'Alpha'])->assertExitCode(0);
    $this->artisan('module', ['action' => 'info', 'name' => 'Alpha', '--format' => 'json'])->assertExitCode(0);

    // health: single module, all modules (json, healthy+warnings, unhealthy+warnings)
    $manager->shouldReceive('checkHealth')->with('Alpha')->once()->andReturn([
        'errors' => [],
        'warnings' => ['Minor warning'],
    ]);
    $this->artisan('module', ['action' => 'health', 'name' => 'Alpha'])->assertExitCode(0);

    $manager->shouldReceive('checkAllHealth')->once()->andReturn([
        'Alpha' => ['errors' => [], 'warnings' => []],
    ]);
    $this->artisan('module', ['action' => 'health', '--format' => 'json'])->assertExitCode(0);

    $manager->shouldReceive('checkAllHealth')->once()->andReturn([
        'Alpha' => ['errors' => [], 'warnings' => []],
        'Beta' => ['errors' => ['Missing provider'], 'warnings' => ['Deprecated hook']],
    ]);
    $this->artisan('module', ['action' => 'health'])->assertExitCode(1);

    // unknown action -> help
    $this->artisan('module', ['action' => 'unknown-action'])->assertExitCode(0);
});

it('runs ModuleAutoloadCommand across success, failure, and composer.phar branches', function (): void {
    $originalBasePath = $this->app->basePath();
    $originalGetenvPath = getenv('PATH');
    $hadEnvPath = array_key_exists('PATH', $_ENV);
    $originalEnvPath = $_ENV['PATH'] ?? null;
    $hadServerPath = array_key_exists('PATH', $_SERVER);
    $originalServerPath = $_SERVER['PATH'] ?? null;

    $tempDir = sys_get_temp_dir().'/cov-autoload-'.uniqid('', true);
    $tempBin = "{$tempDir}/bin";
    $pharPath = "{$tempDir}/composer.phar";
    File::makeDirectory($tempBin, 0755, true);

    try {
        $this->app->setBasePath($tempDir);

        // 1. composer.phar exists and exits 0 (covers phar branch + output callback + SUCCESS)
        file_put_contents($pharPath, "<?php echo \"Autoload ok\\n\"; exit(0);\n");
        $this->artisan('module:dump-autoload')->assertExitCode(0);

        // 2. composer.phar exists and exits 1 (covers FAILURE branch)
        file_put_contents($pharPath, "<?php fwrite(STDERR, \"Autoload fail\\n\"); exit(1);\n");
        $this->artisan('module:dump-autoload')->assertExitCode(1);

        // 3. composer.phar absent (covers ['composer'] fallback branch)
        @unlink($pharPath);
        $fakeComposer = "{$tempBin}/composer";
        file_put_contents($fakeComposer, "#!/bin/sh\necho \"Fake composer ok\"\nexit 0\n");
        chmod($fakeComposer, 0755);

        $newPath = $tempBin.PATH_SEPARATOR.($originalGetenvPath !== false ? $originalGetenvPath : '');
        putenv("PATH={$newPath}");
        $_ENV['PATH'] = $newPath;
        $_SERVER['PATH'] = $newPath;

        $this->artisan('module:dump-autoload')->assertExitCode(0);
    } finally {
        $this->app->setBasePath($originalBasePath);

        if ($originalGetenvPath === false) {
            putenv('PATH');
        } else {
            putenv("PATH={$originalGetenvPath}");
        }

        if ($hadEnvPath) {
            $_ENV['PATH'] = $originalEnvPath;
        } else {
            unset($_ENV['PATH']);
        }

        if ($hadServerPath) {
            $_SERVER['PATH'] = $originalServerPath;
        } else {
            unset($_SERVER['PATH']);
        }

        File::deleteDirectory($tempDir);
    }
});
