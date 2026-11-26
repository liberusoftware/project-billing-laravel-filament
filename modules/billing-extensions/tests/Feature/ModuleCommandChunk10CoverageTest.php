<?php

declare(strict_types=1);

use App\Modules\Contracts\ModuleInterface;
use App\Modules\ModuleManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

$makeStubModule = static fn (
    string $name,
    string $version,
    string $description,
    array $dependencies,
    bool $enabled,
): ModuleInterface => new class($name, $version, $description, $dependencies, $enabled) implements ModuleInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly string $description,
        private readonly array $dependencies,
        private bool $enabled,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function install(): void
    {
        $this->enabled = true;
    }

    public function uninstall(): void
    {
        $this->enabled = false;
    }

    public function getConfig(): array
    {
        return [];
    }
};

it('lists empty modules in both table and json formats', function (): void {
    $manager = Mockery::mock(ModuleManager::class);
    $this->app->instance(ModuleManager::class, $manager);

    $manager->shouldReceive('all')->twice()->andReturn(new Collection([]));

    $this->artisan('module', ['action' => 'list'])
        ->expectsOutput('Modules: —')
        ->expectsOutput('Count: 0')
        ->assertExitCode(0);

    $exitCode = Artisan::call('module', ['action' => 'list', '--format' => 'json']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"modules": []')
        ->and($output)->toContain('"count": 0');
});

it('lists populated modules in both table and json formats', function () use ($makeStubModule): void {
    $alpha = $makeStubModule('Alpha', '1.2.0', 'Alpha module', ['Core', 'Auth'], true);
    $beta = $makeStubModule('Beta', '0.9.0', 'Beta module', [], false);

    $manager = Mockery::mock(ModuleManager::class);
    $this->app->instance(ModuleManager::class, $manager);

    $manager->shouldReceive('all')->twice()->andReturn(new Collection([$alpha, $beta]));

    $tableExitCode = Artisan::call('module', ['action' => 'list']);
    $tableOutput = Artisan::output();

    expect($tableExitCode)->toBe(0)
        ->and($tableOutput)->toContain('Alpha')
        ->and($tableOutput)->toContain('1.2.0')
        ->and($tableOutput)->toContain('Enabled')
        ->and($tableOutput)->toContain('Core, Auth')
        ->and($tableOutput)->toContain('Beta')
        ->and($tableOutput)->toContain('Disabled')
        ->and($tableOutput)->toContain('—');

    $jsonExitCode = Artisan::call('module', ['action' => 'list', '--format' => 'json']);
    $jsonOutput = Artisan::output();

    expect($jsonExitCode)->toBe(0)
        ->and($jsonOutput)->toContain('"name": "Alpha"')
        ->and($jsonOutput)->toContain('"status": "enabled"')
        ->and($jsonOutput)->toContain('"dependencies": "Core, Auth"')
        ->and($jsonOutput)->toContain('"name": "Beta"')
        ->and($jsonOutput)->toContain('"status": "disabled"');
});

it('handles enable, disable, and install actions including missing name, not found, and exception branches', function (): void {
    $manager = Mockery::mock(ModuleManager::class);
    $this->app->instance(ModuleManager::class, $manager);

    // enable
    $this->artisan('module', ['action' => 'enable'])
        ->expectsOutput('Module name is required.')
        ->assertExitCode(1);

    $manager->shouldReceive('enable')->with('Alpha')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'enable', 'name' => 'Alpha'])
        ->expectsOutput("Module 'Alpha' enabled.")
        ->assertExitCode(0);

    $manager->shouldReceive('enable')->with('Missing')->once()->andReturn(false);
    $this->artisan('module', ['action' => 'enable', 'name' => 'Missing'])
        ->expectsOutput("Module 'Missing' not found.")
        ->assertExitCode(1);

    $manager->shouldReceive('enable')->with('Broken')->once()->andThrow(new Exception('Unmet dependencies.'));
    $this->artisan('module', ['action' => 'enable', 'name' => 'Broken'])
        ->expectsOutput("Failed to enable 'Broken': Unmet dependencies.")
        ->assertExitCode(1);

    // disable
    $this->artisan('module', ['action' => 'disable'])
        ->expectsOutput('Module name is required.')
        ->assertExitCode(1);

    $manager->shouldReceive('disable')->with('Alpha')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'disable', 'name' => 'Alpha'])
        ->expectsOutput("Module 'Alpha' disabled.")
        ->assertExitCode(0);

    $manager->shouldReceive('disable')->with('Missing')->once()->andReturn(false);
    $this->artisan('module', ['action' => 'disable', 'name' => 'Missing'])
        ->expectsOutput("Module 'Missing' not found.")
        ->assertExitCode(1);

    $manager->shouldReceive('disable')->with('Core')->once()->andThrow(new Exception('Has dependents.'));
    $this->artisan('module', ['action' => 'disable', 'name' => 'Core'])
        ->expectsOutput("Failed to disable 'Core': Has dependents.")
        ->assertExitCode(1);

    // install
    $this->artisan('module', ['action' => 'install'])
        ->expectsOutput('Module name is required.')
        ->assertExitCode(1);

    $manager->shouldReceive('install')->with('Alpha')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'install', 'name' => 'Alpha'])
        ->expectsOutput("Module 'Alpha' installd.")
        ->assertExitCode(0);

    $manager->shouldReceive('install')->with('Missing')->once()->andReturn(false);
    $this->artisan('module', ['action' => 'install', 'name' => 'Missing'])
        ->expectsOutput("Module 'Missing' not found.")
        ->assertExitCode(1);

    $manager->shouldReceive('install')->with('Broken')->once()->andThrow(new Exception('Migration failed.'));
    $this->artisan('module', ['action' => 'install', 'name' => 'Broken'])
        ->expectsOutput("Failed to install 'Broken': Migration failed.")
        ->assertExitCode(1);
});

it('handles uninstall action with missing name, cancelled confirmation, accepted confirmation, and --force', function (): void {
    $manager = Mockery::mock(ModuleManager::class);
    $this->app->instance(ModuleManager::class, $manager);

    $this->artisan('module', ['action' => 'uninstall'])
        ->expectsOutput('Module name is required.')
        ->assertExitCode(1);

    $this->artisan('module', ['action' => 'uninstall', 'name' => 'Alpha'])
        ->expectsConfirmation("Uninstall module 'Alpha'? This cannot be undone.", 'no')
        ->expectsOutput('Cancelled.')
        ->assertExitCode(0);

    $manager->shouldReceive('uninstall')->with('Alpha')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'uninstall', 'name' => 'Alpha'])
        ->expectsConfirmation("Uninstall module 'Alpha'? This cannot be undone.", 'yes')
        ->expectsOutput("Module 'Alpha' uninstalld.")
        ->assertExitCode(0);

    $manager->shouldReceive('uninstall')->with('Beta')->once()->andReturn(true);
    $this->artisan('module', ['action' => 'uninstall', 'name' => 'Beta', '--force' => true])
        ->expectsOutput("Module 'Beta' uninstalld.")
        ->assertExitCode(0);

    $manager->shouldReceive('uninstall')->with('Missing')->once()->andReturn(false);
    $this->artisan('module', ['action' => 'uninstall', 'name' => 'Missing', '--force' => true])
        ->expectsOutput("Module 'Missing' not found.")
        ->assertExitCode(1);

    $manager->shouldReceive('uninstall')->with('Core')->once()->andThrow(new Exception('Dependents exist.'));
    $this->artisan('module', ['action' => 'uninstall', 'name' => 'Core', '--force' => true])
        ->expectsOutput("Failed to uninstall 'Core': Dependents exist.")
        ->assertExitCode(1);
});

it('handles create action with missing name and delegates to make:module', function (): void {
    $this->artisan('module', ['action' => 'create'])
        ->expectsOutput('Module name is required.')
        ->assertExitCode(1);

    $name = 'ChunkTenCreated';
    $createdPath = base_path("modules/billing-extensions/src/{$name}");
    File::deleteDirectory($createdPath);

    try {
        $this->artisan('module', ['action' => 'create', 'name' => $name, '--force' => true])
            ->expectsOutput("Module '{$name}' created at modules/billing-extensions/src/{$name}")
            ->assertExitCode(0);

        expect(File::exists("{$createdPath}/{$name}Module.php"))->toBeTrue();
    } finally {
        File::deleteDirectory($createdPath);
    }
});

it('handles info action for missing name, not found, default format, and json format', function (): void {
    $manager = Mockery::mock(ModuleManager::class);
    $this->app->instance(ModuleManager::class, $manager);

    $this->artisan('module', ['action' => 'info'])
        ->expectsOutput('Module name is required.')
        ->assertExitCode(1);

    $manager->shouldReceive('getModuleInfo')->with('Ghost')->once()->andReturn([]);
    $this->artisan('module', ['action' => 'info', 'name' => 'Ghost'])
        ->expectsOutput("Module 'Ghost' not found.")
        ->assertExitCode(1);

    $info = [
        'name' => 'Alpha',
        'version' => '1.2.0',
        'dependencies' => ['Core', 'Auth'],
        'tags' => [],
    ];
    $manager->shouldReceive('getModuleInfo')->with('Alpha')->twice()->andReturn($info);

    $this->artisan('module', ['action' => 'info', 'name' => 'Alpha'])
        ->expectsOutput('Name: Alpha')
        ->expectsOutput('Version: 1.2.0')
        ->expectsOutput('Dependencies: Core, Auth')
        ->expectsOutput('Tags: —')
        ->assertExitCode(0);

    $jsonExitCode = Artisan::call('module', ['action' => 'info', 'name' => 'Alpha', '--format' => 'json']);
    $jsonOutput = Artisan::output();

    expect($jsonExitCode)->toBe(0)
        ->and($jsonOutput)->toContain('"name": "Alpha"')
        ->and($jsonOutput)->toContain('"version": "1.2.0"');
});

it('handles health action for single module, all modules, json format, warnings, and issues fallback', function (): void {
    $manager = Mockery::mock(ModuleManager::class);
    $this->app->instance(ModuleManager::class, $manager);

    // Single module healthy without warnings
    $manager->shouldReceive('checkHealth')->with('Alpha')->once()->andReturn([
        'errors' => [],
        'warnings' => [],
    ]);
    $this->artisan('module', ['action' => 'health', 'name' => 'Alpha'])
        ->expectsOutputToContain('Alpha: healthy')
        ->assertExitCode(0);

    // Single module healthy with warnings
    $manager->shouldReceive('checkHealth')->with('Beta')->once()->andReturn([
        'errors' => [],
        'warnings' => ['Dependency disabled: Core'],
    ]);
    $this->artisan('module', ['action' => 'health', 'name' => 'Beta'])
        ->expectsOutputToContain('Beta: healthy (with warnings)')
        ->expectsOutputToContain('Dependency disabled: Core')
        ->assertExitCode(0);

    // Single module unhealthy using legacy 'issues' key + warnings
    $manager->shouldReceive('checkHealth')->with('Gamma')->once()->andReturn([
        'issues' => ['Missing provider'],
        'warnings' => ['Deprecated hook'],
    ]);
    $this->artisan('module', ['action' => 'health', 'name' => 'Gamma'])
        ->expectsOutputToContain('Gamma:')
        ->expectsOutputToContain('Missing provider')
        ->expectsOutputToContain('Deprecated hook')
        ->assertExitCode(1);

    // All modules in json format
    $manager->shouldReceive('checkAllHealth')->once()->andReturn([
        'Alpha' => ['errors' => [], 'warnings' => []],
    ]);
    $this->artisan('module', ['action' => 'health', '--format' => 'json'])
        ->expectsOutputToContain('"Alpha"')
        ->assertExitCode(0);

    // All modules healthy in default format
    $manager->shouldReceive('checkAllHealth')->once()->andReturn([
        'Alpha' => ['errors' => [], 'warnings' => []],
    ]);
    $this->artisan('module', ['action' => 'health'])
        ->expectsOutputToContain('Alpha: healthy')
        ->assertExitCode(0);

    // All modules with errors and warnings in default format
    $manager->shouldReceive('checkAllHealth')->once()->andReturn([
        'Alpha' => ['errors' => [], 'warnings' => []],
        'Beta' => ['errors' => ['Broken dependency'], 'warnings' => ['Outdated schema']],
    ]);
    $this->artisan('module', ['action' => 'health'])
        ->expectsOutputToContain('Alpha: healthy')
        ->expectsOutputToContain('Beta:')
        ->expectsOutputToContain('Broken dependency')
        ->expectsOutputToContain('Outdated schema')
        ->assertExitCode(1);
});

it('shows usage help for unknown actions', function (): void {
    $this->artisan('module', ['action' => 'unknown-action'])
        ->expectsOutput('Usage: php artisan module <action> [name] [options]')
        ->expectsOutput('Actions:')
        ->expectsOutput('Options:')
        ->expectsOutputToContain('--format=json')
        ->assertExitCode(0);
});
