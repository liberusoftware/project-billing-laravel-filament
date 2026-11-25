<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('rejects invalid module names in make:module', function (string $invalidName): void {
    $this->artisan('make:module', ['name' => $invalidName])
        ->expectsOutput('Module name must start with an uppercase letter and contain only alphanumeric characters.')
        ->assertExitCode(1);
})->with([
    'lowercase start' => ['invalidName'],
    'single uppercase letter' => ['A'],
    'contains underscore' => ['Bad_Name'],
    'contains hyphen' => ['Bad-Name'],
    'starts with digit' => ['1Module'],
]);

it('scaffolds a standard module with all directories, files, and expected contents', function (): void {
    $name = 'ChunkNineStd';
    $modulePath = base_path("modules/billing-extensions/src/{$name}");
    File::deleteDirectory($modulePath);

    try {
        $this->artisan('make:module', ['name' => $name])
            ->expectsOutput("Module '{$name}' created at modules/billing-extensions/src/{$name}")
            ->expectsOutputToContain('composer dump-autoload')
            ->assertExitCode(0);

        $expectedDirs = [
            'Filament/Resources',
            'Filament/Resources/ChunkNineStdResource/Pages',
            'Filament/Pages',
            'Filament/Widgets',
            'Http/Controllers',
            'Http/Middleware',
            'Models',
            'Providers',
            'Services',
            'config',
            'database/migrations',
            'database/seeders',
            'resources/assets',
            'resources/lang',
            'resources/views',
            'routes',
            'tests',
        ];

        foreach ($expectedDirs as $dir) {
            expect(File::isDirectory("{$modulePath}/{$dir}"))->toBeTrue();
        }

        $moduleJson = json_decode(File::get("{$modulePath}/module.json"), true, 512, JSON_THROW_ON_ERROR);
        expect($moduleJson)->toMatchArray([
            'name' => $name,
            'version' => '1.0.0',
            'description' => "The {$name} module.",
            'dependencies' => [],
            'config' => [],
        ]);

        expect(File::get("{$modulePath}/{$name}Module.php"))
            ->toContain('namespace App\Modules\ChunkNineStd;')
            ->toContain('class ChunkNineStdModule extends BaseModule')
            ->toContain("Log::info('ChunkNineStd module enabled.');")
            ->toContain("Log::info('ChunkNineStd module uninstalled.');");

        expect(File::get("{$modulePath}/Providers/{$name}ServiceProvider.php"))
            ->toContain('namespace App\Modules\ChunkNineStd\Providers;')
            ->toContain('class ChunkNineStdServiceProvider extends ServiceProvider');

        expect(File::get("{$modulePath}/routes/web.php"))
            ->toContain("prefix('chunk-nine-std')")
            ->toContain("name('chunk-nine-std.')");

        expect(File::get("{$modulePath}/routes/api.php"))
            ->toContain("prefix('api/chunk-nine-std')")
            ->toContain("name('api.chunk-nine-std.')");

        expect(File::get("{$modulePath}/Http/Controllers/{$name}Controller.php"))
            ->toContain('namespace App\Modules\ChunkNineStd\Http\Controllers;')
            ->toContain("return view('ChunkNineStd::index');");

        expect(File::get("{$modulePath}/Models/{$name}.php"))
            ->toContain('namespace App\Modules\ChunkNineStd\Models;')
            ->toContain('class ChunkNineStd extends Model');

        $migrations = File::glob("{$modulePath}/database/migrations/*_create_chunk_nine_stds_table.php");
        expect($migrations)->toHaveCount(1);
        expect(File::get($migrations[0]))
            ->toContain("Schema::create('chunk_nine_stds'")
            ->toContain("Schema::dropIfExists('chunk_nine_stds')");

        expect(File::get("{$modulePath}/resources/views/index.blade.php"))
            ->toContain('Welcome to the ChunkNineStd module.');

        expect(File::get("{$modulePath}/config/chunkninestd.php"))
            ->toContain('// ChunkNineStd module configuration');

        expect(File::get("{$modulePath}/Filament/Resources/{$name}Resource.php"))
            ->toContain('namespace App\Modules\ChunkNineStd\Filament\Resources;')
            ->toContain('class ChunkNineStdResource extends Resource')
            ->toContain('Pages\ListChunkNineStds::route');

        expect(File::get("{$modulePath}/Filament/Resources/{$name}Resource/Pages/List{$name}s.php"))
            ->toContain('class ListChunkNineStds extends ListRecords');

        expect(File::get("{$modulePath}/Filament/Resources/{$name}Resource/Pages/Create{$name}.php"))
            ->toContain('class CreateChunkNineStd extends CreateRecord');

        expect(File::get("{$modulePath}/Filament/Resources/{$name}Resource/Pages/Edit{$name}.php"))
            ->toContain('class EditChunkNineStd extends EditRecord');

        expect(File::get("{$modulePath}/tests/{$name}ModuleTest.php"))
            ->toContain('namespace App\Modules\ChunkNineStd\Tests;')
            ->toContain('class ChunkNineStdModuleTest extends TestCase');
    } finally {
        File::deleteDirectory($modulePath);
    }
});

it('refuses to overwrite an existing module unless --force is passed', function (): void {
    $name = 'ChunkNineForce';
    $modulePath = base_path("modules/billing-extensions/src/{$name}");
    File::deleteDirectory($modulePath);

    try {
        $this->artisan('make:module', ['name' => $name])
            ->assertExitCode(0);

        File::put("{$modulePath}/module.json", '{"custom":true}');

        $this->artisan('make:module', ['name' => $name])
            ->expectsOutput("Module '{$name}' already exists. Use --force to overwrite.")
            ->assertExitCode(1);

        expect(File::get("{$modulePath}/module.json"))->toBe('{"custom":true}');

        $this->artisan('make:module', ['name' => $name, '--force' => true])
            ->expectsOutput("Module '{$name}' created at modules/billing-extensions/src/{$name}")
            ->assertExitCode(0);

        $regenerated = json_decode(File::get("{$modulePath}/module.json"), true, 512, JSON_THROW_ON_ERROR);
        expect($regenerated['name'])->toBe($name);
    } finally {
        File::deleteDirectory($modulePath);
    }
});

it('scaffolds a modular layout module under app-modules with composer.json and custom namespace', function (): void {
    $name = 'ChunkNineMod';
    $appModulesDir = base_path('app-modules');
    $appModulesExisted = File::exists($appModulesDir);
    $modulePath = base_path("app-modules/{$name}");
    File::deleteDirectory($modulePath);

    config()->set('modules.alt_namespace', 'CustomModules\\');

    try {
        $this->artisan('make:module', ['name' => $name, '--modular' => true])
            ->expectsOutput("Module '{$name}' created at app-modules/{$name}")
            ->expectsOutputToContain('composer dump-autoload')
            ->assertExitCode(0);

        $expectedDirs = [
            'src/Filament/Resources',
            'src/Filament/Resources/ChunkNineModResource/Pages',
            'src/Filament/Pages',
            'src/Filament/Widgets',
            'src/Http/Controllers',
            'src/Http/Middleware',
            'src/Models',
            'src/Providers',
            'src/Services',
            'config',
            'database/migrations',
            'database/seeders',
            'database/factories',
            'resources/assets',
            'resources/lang',
            'resources/views',
            'routes',
            'tests/Unit',
            'tests/Feature',
        ];

        foreach ($expectedDirs as $dir) {
            expect(File::isDirectory("{$modulePath}/{$dir}"))->toBeTrue();
        }

        $composerJson = json_decode(File::get("{$modulePath}/composer.json"), true, 512, JSON_THROW_ON_ERROR);
        expect($composerJson)->toMatchArray([
            'name' => 'liberu/chunkninemod',
            'description' => "The {$name} module for Liberu Billing.",
            'type' => 'library',
            'autoload' => [
                'psr-4' => [
                    'CustomModules\\ChunkNineMod\\' => 'src/',
                ],
            ],
        ]);

        expect(File::exists("{$modulePath}/src/module.json"))->toBeTrue()
            ->and(File::get("{$modulePath}/src/{$name}Module.php"))
            ->toContain('namespace CustomModules\ChunkNineMod;')
            ->and(File::get("{$modulePath}/src/Providers/{$name}ServiceProvider.php"))
            ->toContain('namespace CustomModules\ChunkNineMod\Providers;')
            ->and(File::get("{$modulePath}/src/Http/Controllers/{$name}Controller.php"))
            ->toContain('namespace CustomModules\ChunkNineMod\Http\Controllers;')
            ->and(File::get("{$modulePath}/src/Models/{$name}.php"))
            ->toContain('namespace CustomModules\ChunkNineMod\Models;')
            ->and(File::get("{$modulePath}/src/Filament/Resources/{$name}Resource.php"))
            ->toContain('namespace CustomModules\ChunkNineMod\Filament\Resources;')
            ->and(File::get("{$modulePath}/tests/{$name}ModuleTest.php"))
            ->toContain('namespace CustomModules\ChunkNineMod\Tests;');

        $migrations = File::glob("{$modulePath}/database/migrations/*_create_chunk_nine_mods_table.php");
        expect($migrations)->toHaveCount(1);

        // Existing modular directory without --force fails
        $this->artisan('make:module', ['name' => $name, '--modular' => true])
            ->expectsOutput("Module '{$name}' already exists. Use --force to overwrite.")
            ->assertExitCode(1);

        // Existing modular directory with --force succeeds
        $this->artisan('make:module', ['name' => $name, '--modular' => true, '--force' => true])
            ->assertExitCode(0);
    } finally {
        File::deleteDirectory($modulePath);
        if (! $appModulesExisted) {
            File::deleteDirectory($appModulesDir);
        }
    }
});
