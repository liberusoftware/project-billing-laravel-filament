<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ModulePackageTestCase;
use Tests\ModuleTestCases\IdentityFilamentFeatureTestCase;
use Tests\ModuleTestCases\LocalizationFeatureTestCase;
use Tests\ModuleTestCases\LocalizationLivewireFeatureTestCase;
use Tests\ModuleTestCases\ModuleManagerUnitTestCase;
use Tests\ModuleTestCases\OrganizationsFilamentFeatureTestCase;
use Tests\ModuleTestCases\SearchFeatureTestCase;
use Tests\ModuleTestCases\SettingsFeatureTestCase;
use Tests\ModuleTestCases\SettingsFilamentFeatureTestCase;
use Tests\ModuleTestCases\SocialstreamFeatureTestCase;
use Tests\TestCase;

require_once __DIR__.'/../modules/theme-support/tests/Fixtures/ComposerAvailability.php';
require_once __DIR__.'/../modules/theme-support/tests/Helpers.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('../modules/billing-*/tests/Feature');

pest()->extend(TestCase::class)->in('../modules/billing-*/tests/Unit');

// The runtime-selection rule reads config('modules.*'), which needs a booted container.
pest()->extend(TestCase::class)->in('../modules/billing-application/tests/Architecture');

pest()->extend(ModulePackageTestCase::class)->in(
    '../modules/activity-comments/tests',
    '../modules/analytics-core/tests',
    '../modules/analytics-google/tests',
    '../modules/analytics-meta/tests',
    '../modules/api-access/tests',
    '../modules/application/tests',
    '../modules/audit/tests',
    '../modules/currency-context/tests',
    '../modules/developer-experience/tests',
    '../modules/feature-flags/tests',
    '../modules/files-media/tests',
    '../modules/identity-core/tests',
    '../modules/import-export/tests',
    '../modules/jetstream-bridge/tests',
    '../modules/localization-core/tests/Unit',
    '../modules/localization-mymemory/tests',
    '../modules/module-manager-filament/tests',
    '../modules/notifications/tests',
    '../modules/observability/tests',
    '../modules/organizations-teams/tests/Unit',
    '../modules/profiles/tests',
    '../modules/search-api/tests',
    '../modules/sessions-devices/tests',
    '../modules/tenancy/tests',
    '../modules/theme-support/tests',
    '../modules/theme-support-livewire/tests',
    '../modules/two-factor-authentication/tests',
    '../modules/users-roles/tests',
    '../modules/webhooks/tests',
);

pest()->extend(ModuleManagerUnitTestCase::class)->in('../modules/module-manager/tests');

pest()->extend(LocalizationFeatureTestCase::class)->in('../modules/localization-core/tests/Feature');
pest()->extend(LocalizationLivewireFeatureTestCase::class)->in('../modules/localization-core-livewire/tests/Feature');
pest()->extend(SearchFeatureTestCase::class)->in('../modules/search/tests/Feature');
pest()->extend(SettingsFeatureTestCase::class)->in('../modules/settings/tests/Feature');
pest()->extend(SocialstreamFeatureTestCase::class)->in('../modules/identity-socialstream/tests/Feature');
pest()->extend(IdentityFilamentFeatureTestCase::class)->in('../modules/identity-core-filament/tests/Feature');
pest()->extend(OrganizationsFilamentFeatureTestCase::class)->in('../modules/organizations-teams-filament/tests/Feature');
pest()->extend(SettingsFilamentFeatureTestCase::class)->in('../modules/settings-filament/tests/Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(
        '../modules/module-billing-*/tests',
        '../modules/module-liberu-*/tests',
        '../modules/liberu-*/tests',
    );
