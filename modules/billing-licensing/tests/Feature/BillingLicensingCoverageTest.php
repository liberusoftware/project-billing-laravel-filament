<?php

declare(strict_types=1);

use App\Enums\LicenseStatus;
use App\Filament\Admin\Resources\Licenses\Pages\EditLicense;
use App\Filament\Admin\Resources\Licenses\Pages\ListLicenses as AdminListLicenses;
use App\Filament\Client\Resources\LicenseResource as ClientLicenseResource;
use App\Filament\Client\Resources\LicenseResource\Pages\ViewLicense as ClientViewLicense;
use App\Http\Controllers\InstallationController;
use App\Models\Customer;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\License;
use App\Models\LicenseInstance;
use App\Models\LicenseReissue;
use App\Models\Team;
use App\Models\User;
use App\Services\InstallationScriptService;
use App\Services\LicenseService;
use App\Services\ReleaseManagementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('covers LicenseStatus enum labels and colors', function (): void {
    expect(LicenseStatus::Active->getLabel())->toBe('Active')
        ->and(LicenseStatus::Suspended->getLabel())->toBe('Suspended')
        ->and(LicenseStatus::ReissuePending->getLabel())->toBe('Reissue pending')
        ->and(LicenseStatus::Expired->getLabel())->toBe('Expired')
        ->and(LicenseStatus::Active->getColor())->toBe('success')
        ->and(LicenseStatus::Suspended->getColor())->toBe('warning')
        ->and(LicenseStatus::ReissuePending->getColor())->toBe('info')
        ->and(LicenseStatus::Expired->getColor())->toBe('danger');
});

it('covers License active scope and LicenseInstance and LicenseReissue relations', function (): void {
    $active = License::factory()->create(['status' => LicenseStatus::Active]);
    $suspended = License::factory()->create(['status' => LicenseStatus::Suspended]);

    expect(License::query()->active()->pluck('id')->all())
        ->toContain($active->id)
        ->not->toContain($suspended->id);

    $instance = LicenseInstance::create([
        'license_id' => $active->id,
        'identifier' => 'app.example.test',
        'ip_address' => '127.0.0.1',
    ]);
    expect($instance->license->id)->toBe($active->id);

    $reissue = LicenseReissue::create([
        'license_id' => $active->id,
        'reissued_by' => null,
    ]);
    expect($reissue->license->id)->toBe($active->id);
});

it('covers ReleaseManagementService empty changelog branch', function (): void {
    $team = Team::factory()->create();
    $connection = GitConnection::create([
        'team_id' => $team->id,
        'provider' => 'github',
        'name' => 'Test Git',
        'base_url' => 'https://api.github.com',
        'access_token' => 'secret-token',
    ]);
    $repository = GitRepository::create([
        'git_connection_id' => $connection->id,
        'external_id' => 'repo-1',
        'full_name' => 'acme/app',
        'default_branch' => 'main',
        'web_url' => 'https://github.com/acme/app',
    ]);

    $service = app(ReleaseManagementService::class);

    expect($service->generateChangelog($repository))->toBe('No recorded changes.');
});

it('covers InstallationScriptService validation, control panels, and execution paths', function (): void {
    expect(fn () => new InstallationScriptService(
        'cpanel',
        'not a valid repo',
        'example.com',
        'db_name',
        'db_user',
        'secret',
    ))->toThrow(Exception::class, 'Invalid git repository URL');

    expect(fn () => new InstallationScriptService(
        'cpanel',
        'https://github.com/acme/app.git',
        'bad domain!',
        'db_name',
        'db_user',
        'secret',
    ))->toThrow(Exception::class, 'Invalid domain');

    foreach (['plesk' => '~/httpdocs', 'directadmin' => '~/domains/example.com/public_html', 'virtualmin' => '~/public_html'] as $panel => $expectedPath) {
        $service = new InstallationScriptService(
            $panel,
            'https://github.com/acme/app.git',
            'example.com',
            'db_name',
            'db_user',
            'secret',
        );

        expect($service->generateScript())->toContain($expectedPath);
    }

    // Success execution path using a temporary bash shim on PATH
    $tmpBin = sys_get_temp_dir().'/fake_bash_ok_'.uniqid();
    mkdir($tmpBin, 0700, true);
    $fakeBash = $tmpBin.'/bash';
    file_put_contents($fakeBash, "#!/bin/sh\necho 'ok'\nexit 0\n");
    chmod($fakeBash, 0700);
    $originalPath = getenv('PATH') ?: '';

    try {
        putenv("PATH={$tmpBin}:{$originalPath}");

        $service = new InstallationScriptService(
            'cpanel',
            'https://github.com/acme/app.git',
            'example.com',
            'db_name',
            'db_user',
            'secret',
        );

        expect($service->execute())->toBeTrue();

        $controller = new InstallationController;
        $request = Request::create('/api/install', 'POST', [
            'control_panel' => 'cpanel',
            'git_repo' => 'https://github.com/acme/app.git',
            'domain' => 'example.com',
            'db_name' => 'db_name',
            'db_user' => 'db_user',
            'db_password' => 'secret',
        ]);
        $response = $controller->install($request);
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getData(true))->toBe(['message' => 'Installation completed successfully']);

        // Failure execution path using a bash shim that exits 1
        file_put_contents($fakeBash, "#!/bin/sh\necho 'boom' >&2\nexit 1\n");
        chmod($fakeBash, 0700);

        $failResponse = $controller->install($request);
        expect($failResponse->getStatusCode())->toBe(500)
            ->and($failResponse->getData(true))->toHaveKey('error');
    } finally {
        putenv("PATH={$originalPath}");
        @unlink($fakeBash);
        @rmdir($tmpBin);
    }
});

it('covers Client LicenseResource canCreate and ViewLicense infolist', function (): void {
    $user = User::factory()->create(['email' => 'client-lic@example.com']);
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $customer = Customer::factory()->create([
        'team_id' => $team->id,
        'email' => $user->email,
    ]);
    $license = License::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'status' => LicenseStatus::Active,
    ]);

    expect(ClientLicenseResource::canCreate())->toBeFalse();

    Gate::before(fn () => true);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('client'));
    Filament::setTenant($team);

    Livewire::test(ClientViewLicense::class, ['record' => $license->getRouteKey()])
        ->assertOk();
});

it('covers Admin LicenseResource pages, table actions, reissue error handling, and suspend toggle', function (): void {
    $admin = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $admin->id]);
    $admin->forceFill(['current_team_id' => $team->id])->save();

    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $license = License::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'status' => LicenseStatus::Active,
    ]);

    Gate::before(fn () => true);
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);

    // ListLicenses + toggleSuspend (Active -> Suspended -> Active) + reissue success
    Livewire::test(AdminListLicenses::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$license])
        ->callTableAction('toggleSuspend', $license)
        ->assertHasNoTableActionErrors();

    expect($license->fresh()->status)->toBe(LicenseStatus::Suspended);

    Livewire::test(AdminListLicenses::class)
        ->callTableAction('toggleSuspend', $license)
        ->assertHasNoTableActionErrors();

    expect($license->fresh()->status)->toBe(LicenseStatus::Active);

    Livewire::test(AdminListLicenses::class)
        ->callTableAction('reissue', $license)
        ->assertHasNoTableActionErrors();

    // Reissue catch branch when LicenseService throws
    $mock = Mockery::mock(LicenseService::class);
    $mock->shouldReceive('reissue')->once()->andThrow(new RuntimeException('Reissue failed'));
    app()->instance(LicenseService::class, $mock);

    Livewire::test(AdminListLicenses::class)
        ->callTableAction('reissue', $license);

    // EditLicense page
    Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
        ->assertOk();
});
