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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);
});

it('returns general group name from GeneralSettings', function (): void {
    expect(GeneralSettings::group())->toBe('general');
});

it('renders and saves ManageGeneralSettings page', function (): void {
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
        ->assertCanSeeTableRecords([$record]);

    Livewire::test(CreateSiteSettings::class)
        ->assertOk()
        ->fillForm([
            'name' => 'Second Site',
            'email' => 'second@example.test',
            'currency' => 'EUR',
            'default_language' => 'de',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(EditSiteSettings::class, ['record' => $record->getRouteKey()])
        ->assertOk()
        ->fillForm([
            'name' => 'Updated Site',
            'email' => 'updated@example.test',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->name)->toBe('Updated Site');
});
