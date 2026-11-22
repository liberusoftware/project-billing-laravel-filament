<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Reporting\Actions\RecordReportingMetric;
use Liberu\Billing\Reporting\Livewire\Components\CustomerBillingSummary;
use Liberu\Billing\Reporting\Livewire\Components\ReportingDashboard;
use Liberu\Billing\Reporting\Livewire\Components\ReportingMetrics;
use Liberu\Billing\Reporting\Livewire\Components\ReportingSnapshots;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders and operates all billing reporting Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.reporting.read', 'billing.reporting.write']);

    Livewire::test(ReportingDashboard::class)
        ->assertSee('No reporting metrics found.')
        ->set('metric', 'mrr')
        ->set('periodStart', '2026-08-01')
        ->set('periodEnd', '2026-08-31')
        ->set('currency', 'USD')
        ->call('calculate')
        ->assertHasNoErrors()
        ->assertSee('Metric calculated.')
        ->assertSee('MRR');

    app(RecordReportingMetric::class)->execute((int) $user->current_team_id, [
        'metric' => 'revenue',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'value' => 1250,
        'currency' => 'USD',
    ]);

    Livewire::test(ReportingMetrics::class)
        ->assertSee('REVENUE')
        ->call('export')
        ->assertFileDownloaded('billing-reporting-metrics.csv');

    Livewire::test(ReportingSnapshots::class)
        ->assertSee('No metric snapshots found.')
        ->set('name', 'August Snapshot')
        ->call('createSnapshot')
        ->assertHasNoErrors()
        ->assertSet('name', '')
        ->assertSee('Metric snapshot created.')
        ->assertSee('August Snapshot');

    Livewire::test(CustomerBillingSummary::class)
        ->set('customerId', 5)
        ->set('currency', 'USD')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSet('summary.customer_id', 5)
        ->assertSee('Invoiced');
});

it('forbids Livewire reporting components when the user has no current team', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['billing.reporting.read', 'billing.reporting.write']);

    Livewire::test(ReportingDashboard::class)->assertForbidden();
    Livewire::test(ReportingMetrics::class)->assertForbidden();
    Livewire::test(ReportingSnapshots::class)->assertForbidden();
    Livewire::test(CustomerBillingSummary::class)
        ->set('customerId', 5)
        ->call('generate')
        ->assertForbidden();
});
