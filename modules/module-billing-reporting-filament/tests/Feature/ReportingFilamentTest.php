<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Reporting\Actions\CreateMetricSnapshot;
use Liberu\Billing\Reporting\Actions\RecordReportingMetric;
use Liberu\Billing\Reporting\Filament\ReportingFilamentPlugin;
use Liberu\Billing\Reporting\Filament\Resources\MetricSnapshotResource;
use Liberu\Billing\Reporting\Filament\Resources\MetricSnapshotResource\Pages\CreateMetricSnapshot as CreateMetricSnapshotPage;
use Liberu\Billing\Reporting\Filament\Resources\MetricSnapshotResource\Pages\ListMetricSnapshots;
use Liberu\Billing\Reporting\Filament\Resources\ReportingMetricResource;
use Liberu\Billing\Reporting\Filament\Resources\ReportingMetricResource\Pages\CreateReportingMetric;
use Liberu\Billing\Reporting\Filament\Resources\ReportingMetricResource\Pages\ListReportingMetrics;
use Liberu\Billing\Reporting\Models\MetricSnapshot;
use Liberu\Billing\Reporting\Models\ReportingMetric;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('manages metric snapshots and reporting metrics through the published Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.reporting.read', 'billing.reporting.write']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    $plugin = ReportingFilamentPlugin::make();
    expect($plugin->getId())->toBe('module-billing-reporting-filament');
    $plugin->boot($panel);

    Livewire::test(CreateMetricSnapshotPage::class)
        ->fillForm(['name' => 'Q3 Snapshot', 'status' => 'ready'])
        ->call('create')
        ->assertHasNoFormErrors();

    $snapshot = MetricSnapshot::query()->where('name', 'Q3 Snapshot')->sole();
    $foreignSnapshot = app(CreateMetricSnapshot::class)->handle($actor->current_team_id + 1, ['name' => 'Foreign Snapshot']);

    Livewire::test(ListMetricSnapshots::class)
        ->assertCanSeeTableRecords([$snapshot])
        ->assertCanNotSeeTableRecords([$foreignSnapshot])
        ->callTableAction('customerSummary', $snapshot, data: ['customer_id' => 5, 'currency' => 'USD']);

    expect(session('module-billing-reporting-summary'))
        ->toBe('Invoiced: 0 | Paid: 0 | Outstanding: 0 | Overdue: 0');

    Livewire::test(CreateReportingMetric::class)
        ->fillForm([
            'metric' => 'revenue',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'value' => 1500,
            'currency' => 'USD',
            'source' => 'filament',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $metric = ReportingMetric::query()->where('source', 'filament')->sole();
    $foreignMetric = app(RecordReportingMetric::class)->execute($actor->current_team_id + 1, [
        'metric' => 'revenue',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'value' => 999,
        'currency' => 'USD',
    ]);

    Livewire::test(ListReportingMetrics::class)
        ->assertCanSeeTableRecords([$metric])
        ->assertCanNotSeeTableRecords([$foreignMetric])
        ->callTableAction('recalculate', $metric, data: ['currency' => 'USD'])
        ->callAction(TestAction::make('exportCsv')->table())
        ->assertFileDownloaded('billing-reporting-metrics.csv');

    expect(MetricSnapshotResource::getEloquentQuery()->pluck('id')->all())->toBe([$snapshot->id])
        ->and(ReportingMetricResource::getEloquentQuery()->where('source', 'filament')->pluck('id')->all())->toBe([$metric->id]);
});
