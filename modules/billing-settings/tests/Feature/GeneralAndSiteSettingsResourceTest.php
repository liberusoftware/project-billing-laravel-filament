<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ManageGeneralSettings;
use App\Filament\Admin\Resources\SiteSettingsResource;
use App\Filament\Admin\Resources\SiteSettingsResource\Pages\CreateSiteSettings;
use App\Filament\Admin\Resources\SiteSettingsResource\Pages\EditSiteSettings;
use App\Filament\Admin\Resources\SiteSettingsResource\Pages\ListSiteSettings;
use App\Models\SiteSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    setPermissionsTeamId($team->id);
    Role::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
        'team_id' => $team->id,
    ]);
    $user->assignRole('super_admin');

    $this->actingAs($user);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);
});

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('returns general group name from GeneralSettings', function (): void {
    expect(GeneralSettings::group())->toBe('general');
});

it('renders and saves ManageGeneralSettings page', function (): void {
    foreach ([
        'site_name' => 'Liberu Billing',
        'site_email' => 'info@example.com',
        'site_phone' => '',
        'site_address' => '',
        'site_country' => '',
        'site_currency' => '$',
        'site_default_language' => 'en',
        'facebook_url' => null,
        'twitter_url' => null,
        'github_url' => null,
        'youtube_url' => null,
        'footer_copyright' => '© Liberu Billing',
    ] as $name => $value) {
        DB::table('settings')->updateOrInsert(
            ['group' => 'general', 'name' => $name],
            ['locked' => false, 'payload' => json_encode($value)]
        );
    }
    app()->forgetInstance(GeneralSettings::class);

    Livewire::test(ManageGeneralSettings::class)
        ->assertOk()
        ->fillForm([
            'site_name' => 'Liberu Test',
            'site_email' => 'billing@liberu.test',
            'site_phone' => '+15551234567',
            'site_address' => '123 Main St',
            'site_country' => 'US',
            'site_currency' => '$',
            'site_default_language' => 'en',
            'facebook_url' => 'https://facebook.com/liberu',
            'twitter_url' => 'https://twitter.com/liberu',
            'github_url' => 'https://github.com/liberu',
            'youtube_url' => 'https://youtube.com/liberu',
            'footer_copyright' => '© Liberu Test',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(GeneralSettings::class);
    expect(app(GeneralSettings::class)->site_name)->toBe('Liberu Test');
});

it('renders SiteSettingsResource list, create, and edit pages', function (): void {
    $record = SiteSettings::create([
        'name' => 'Primary Site',
        'email' => 'admin@example.test',
        'currency' => 'USD',
        'default_language' => 'en',
    ]);

    expect(SiteSettingsResource::getRelations())->toBe([])
        ->and(SiteSettingsResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    Livewire::test(ListSiteSettings::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$record])
        ->assertActionExists(CreateAction::class)
        ->assertTableActionExists(EditAction::class, record: $record)
        ->assertTableActionExists(DeleteAction::class, record: $record)
        ->assertTableBulkActionExists(DeleteBulkAction::class);

    Livewire::test(CreateSiteSettings::class)
        ->assertOk()
        ->fillForm([
            'name' => 'Second Site',
            'email' => 'second@example.test',
            'currency' => 'EUR',
            'default_language' => 'de',
            'address' => '456 Oak Ave',
            'country' => 'DE',
            'phone_01' => '+49123456789',
            'phone_02' => '+49123456780',
            'phone_03' => '+49123456781',
            'facebook' => 'https://facebook.com/second',
            'twitter' => 'https://twitter.com/second',
            'github' => 'https://github.com/second',
            'youtube' => 'https://youtube.com/second',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(EditSiteSettings::class, ['record' => $record->getRouteKey()])
        ->assertOk()
        ->assertActionExists(DeleteAction::class)
        ->fillForm([
            'name' => 'Updated Site',
            'email' => 'updated@example.test',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->name)->toBe('Updated Site');
});
