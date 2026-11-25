<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('runs composer.phar successfully and streams output when composer.phar exists', function (): void {
    $originalBasePath = $this->app->basePath();
    $tempDir = sys_get_temp_dir().'/chunk10-autoload-ok-'.uniqid('', true);
    File::makeDirectory($tempDir, 0755, true);

    try {
        file_put_contents(
            "{$tempDir}/composer.phar",
            "<?php\necho 'Phar args: '.implode(' ', array_slice(\$argv, 1)).\"\\n\";\nexit(0);\n"
        );

        $this->app->setBasePath($tempDir);

        $this->artisan('module:dump-autoload')
            ->expectsOutput('Refreshing Composer autoloader...')
            ->expectsOutputToContain('Phar args: dump-autoload --optimize')
            ->expectsOutput('Autoloader refreshed successfully.')
            ->assertExitCode(0);
    } finally {
        $this->app->setBasePath($originalBasePath);
        File::deleteDirectory($tempDir);
    }
});

it('returns failure when composer.phar exits with a non-zero status', function (): void {
    $originalBasePath = $this->app->basePath();
    $tempDir = sys_get_temp_dir().'/chunk10-autoload-fail-'.uniqid('', true);
    File::makeDirectory($tempDir, 0755, true);

    try {
        file_put_contents(
            "{$tempDir}/composer.phar",
            "<?php\nfwrite(STDERR, \"Simulated phar failure\\n\");\nexit(1);\n"
        );

        $this->app->setBasePath($tempDir);

        $this->artisan('module:dump-autoload')
            ->expectsOutput('Refreshing Composer autoloader...')
            ->expectsOutputToContain('Simulated phar failure')
            ->expectsOutput('composer dump-autoload failed.')
            ->assertExitCode(1);
    } finally {
        $this->app->setBasePath($originalBasePath);
        File::deleteDirectory($tempDir);
    }
});

it('falls back to the composer binary when composer.phar does not exist', function (): void {
    $originalBasePath = $this->app->basePath();
    $originalGetenvPath = getenv('PATH');
    $hadEnvPath = array_key_exists('PATH', $_ENV);
    $originalEnvPath = $_ENV['PATH'] ?? null;
    $hadServerPath = array_key_exists('PATH', $_SERVER);
    $originalServerPath = $_SERVER['PATH'] ?? null;

    $tempDir = sys_get_temp_dir().'/chunk10-autoload-bin-'.uniqid('', true);
    $tempBin = "{$tempDir}/bin";
    File::makeDirectory($tempBin, 0755, true);

    try {
        $fakeComposer = "{$tempBin}/composer";
        file_put_contents(
            $fakeComposer,
            "#!/bin/sh\necho \"Fake composer: \$*\"\nexit 0\n"
        );
        chmod($fakeComposer, 0755);

        $newPath = $tempBin.PATH_SEPARATOR.($originalGetenvPath !== false ? $originalGetenvPath : '');
        putenv("PATH={$newPath}");
        $_ENV['PATH'] = $newPath;
        $_SERVER['PATH'] = $newPath;

        $this->app->setBasePath($tempDir);

        $this->artisan('module:dump-autoload')
            ->expectsOutput('Refreshing Composer autoloader...')
            ->expectsOutputToContain('Fake composer: dump-autoload --optimize')
            ->expectsOutput('Autoloader refreshed successfully.')
            ->assertExitCode(0);
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
