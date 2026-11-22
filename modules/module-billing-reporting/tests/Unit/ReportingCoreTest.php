<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Reporting\Actions\CalculateReportingMetric;
use Liberu\Billing\Reporting\Actions\CreateMetricSnapshot;
use Liberu\Billing\Reporting\Actions\ExportReportingMetrics;
use Liberu\Billing\Reporting\Actions\GenerateCustomerBillingSummary;
use Liberu\Billing\Reporting\Actions\RecordReportingMetric;
use Liberu\Billing\Reporting\Policies\MetricSnapshotPolicy;
use Liberu\Billing\Reporting\Policies\ReportingMetricPolicy;
use Liberu\Billing\Reporting\Queries\ListMetricSnapshots;
use Liberu\Billing\Reporting\Queries\ListReportingMetrics;
use Liberu\Billing\Reporting\Queries\ListReportingRecords;

uses(RefreshDatabase::class);

it('validates inputs for calculating reporting metrics', function (): void {
    $calculator = app(CalculateReportingMetric::class);
    $start = CarbonImmutable::parse('2026-08-01');
    $end = CarbonImmutable::parse('2026-08-31');

    expect(fn () => $calculator->execute(0, 'mrr', $start, $end))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->execute(1, 'invalid', $start, $end))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->execute(1, 'mrr', $end, $start))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $calculator->execute(1, 'mrr', $start, $end, 'US'))->toThrow(InvalidArgumentException::class);
});

it('calculates all reporting metric types and billing intervals', function (): void {
    $now = CarbonImmutable::parse('2026-08-25 12:00:00');
    $start = $now->startOfMonth();

    $yearlyPlan = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => 10, 'name' => 'Yearly', 'pricing_model' => 'flat', 'currency' => 'USD',
        'unit_amount_minor' => 12000, 'billing_interval' => 'yearly', 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $weeklyPlan = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => 10, 'name' => 'Weekly', 'pricing_model' => 'flat', 'currency' => 'USD',
        'unit_amount_minor' => 120, 'billing_interval' => 'weekly', 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $dailyPlan = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => 10, 'name' => 'Daily', 'pricing_model' => 'flat', 'currency' => 'USD',
        'unit_amount_minor' => 12, 'billing_interval' => 'daily', 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $monthlyPlan = DB::table('billing_pricing_plans')->insertGetId([
        'team_id' => 10, 'name' => 'Monthly', 'pricing_model' => 'flat', 'currency' => 'USD',
        'unit_amount_minor' => 500, 'billing_interval' => 'monthly', 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('billing_subscriptions')->insert([
        ['team_id' => 10, 'pricing_plan_id' => $yearlyPlan, 'status' => 'active', 'starts_at' => $start->subMonth(), 'cancelled_at' => null, 'auto_renew' => true, 'id_protection' => false, 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => 10, 'pricing_plan_id' => $weeklyPlan, 'status' => 'active', 'starts_at' => $start->subMonth(), 'cancelled_at' => null, 'auto_renew' => true, 'id_protection' => false, 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => 10, 'pricing_plan_id' => $dailyPlan, 'status' => 'trialing', 'starts_at' => $start->subMonth(), 'cancelled_at' => $now->addDay(), 'auto_renew' => true, 'id_protection' => false, 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => 10, 'pricing_plan_id' => $monthlyPlan, 'status' => 'active', 'starts_at' => $start->subMonth(), 'cancelled_at' => $now->subDays(2), 'auto_renew' => true, 'id_protection' => false, 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('billing_invoices')->insert([
        ['team_id' => 10, 'status' => 'paid', 'currency' => 'USD', 'subtotal_minor' => 1000, 'tax_minor' => 200, 'total_minor' => 1200, 'finalized_at' => $now, 'due_at' => $now->subDay(), 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => 10, 'status' => 'finalized', 'currency' => 'USD', 'subtotal_minor' => 500, 'tax_minor' => 50, 'total_minor' => 550, 'finalized_at' => $now, 'due_at' => $now->subDay(), 'created_at' => $now, 'updated_at' => $now],
    ]);

    $meterId = DB::table('billing_usage_meters')->insertGetId([
        'team_id' => 10, 'name' => 'Requests', 'code' => 'req', 'unit' => 'req',
        'unit_price_minor' => 10, 'currency' => 'USD', 'active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('billing_usage_records')->insert([
        'team_id' => 10, 'meter_id' => $meterId, 'event_key' => 'ev-1', 'quantity' => 5,
        'unit_price_minor' => 10, 'amount_minor' => 50, 'currency' => 'USD',
        'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('billing_provisioned_services')->insert([
        ['team_id' => 10, 'provider' => 'cpanel', 'external_id' => 'ext-1', 'state' => 'active', 'created_at' => $now->subDay(), 'updated_at' => $now->subDay()],
        ['team_id' => 10, 'provider' => 'cpanel', 'external_id' => 'ext-2', 'state' => 'suspended', 'created_at' => $now->subDay(), 'updated_at' => $now->subDay()],
    ]);

    DB::table('billing_collection_cases')->insert([
        ['team_id' => 10, 'type' => 'dunning', 'status' => 'open', 'amount_minor' => 800, 'currency' => 'USD', 'created_at' => $now->subDay(), 'updated_at' => $now->subDay()],
        ['team_id' => 10, 'type' => 'dunning', 'status' => 'recovered', 'amount_minor' => 400, 'currency' => 'USD', 'created_at' => $now->subDay(), 'updated_at' => $now->subDay()],
    ]);

    DB::table('billing_payments')->insert([
        'team_id' => 10, 'amount_minor' => 1200, 'currency' => 'USD', 'status' => 'captured',
        'gateway' => 'test', 'captured_at' => $now, 'refunded_minor' => 200, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $calculator = app(CalculateReportingMetric::class);

    // yearly (1000) + weekly (520) + daily (365) = 1885; monthly was cancelled before $now
    expect($calculator->execute(10, 'mrr', $start, $now, 'usd')['value'])->toBe(1885.0)
        ->and($calculator->execute(10, 'mrr', $start, $now->subDays(5), 'USD')['value'])->toBe(2385.0)
        ->and($calculator->execute(10, 'arr', $start, $now, 'USD')['value'])->toBe(1885.0 * 12)
        ->and($calculator->execute(10, 'churn', $start, $now)['value'])->toBe(25.0)
        ->and($calculator->execute(999, 'churn', $start, $now)['value'])->toBe(0.0)
        ->and($calculator->execute(10, 'revenue', $start, $now, 'USD')['value'])->toBe(1750.0)
        ->and($calculator->execute(10, 'tax', $start, $now, 'USD')['value'])->toBe(250.0)
        ->and($calculator->execute(10, 'aging', $start, $now, 'USD')['value'])->toBe(550.0)
        ->and($calculator->execute(10, 'usage', $start, $now, 'USD')['value'])->toBe(50.0)
        ->and($calculator->execute(10, 'provisioning', $start, $now)['value'])->toBe(1.0)
        ->and($calculator->execute(10, 'collection', $start, $now, 'USD')['value'])->toBe(800.0)
        ->and($calculator->execute(10, 'provider', $start, $now, 'USD')['value'])->toBe(1000.0);
});

it('returns zero from CalculateReportingMetric when backing tables are missing', function (): void {
    Schema::shouldReceive('hasTable')->andReturn(false);

    $calculator = app(CalculateReportingMetric::class);
    $start = CarbonImmutable::parse('2026-08-01');
    $end = CarbonImmutable::parse('2026-08-31');

    foreach (['mrr', 'arr', 'churn', 'aging', 'revenue', 'tax', 'usage', 'provisioning', 'collection', 'provider'] as $metric) {
        expect($calculator->execute(10, $metric, $start, $end, 'USD')['value'])->toBe(0.0);
    }
});

it('validates and exports reporting metrics including missing table fallback', function (): void {
    $exporter = app(ExportReportingMetrics::class);

    expect(fn () => $exporter->execute(0))->toThrow(InvalidArgumentException::class);

    Schema::shouldReceive('hasTable')->with('billing_reporting_metrics')->andReturn(false);

    expect(trim($exporter->execute(10)))->toBe('metric,period_start,period_end,value,currency,source');
});

it('validates and generates customer billing summaries', function (): void {
    $action = app(GenerateCustomerBillingSummary::class);

    expect(fn () => $action->execute(0, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $action->execute(1, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $action->execute(1, 1, 'INVALID'))->toThrow(InvalidArgumentException::class);

    $now = CarbonImmutable::now();
    DB::table('billing_invoices')->insert([
        ['team_id' => 10, 'customer_id' => 5, 'status' => 'paid', 'currency' => 'USD', 'total_minor' => 1000, 'due_at' => $now->subDays(10), 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => 10, 'customer_id' => 5, 'status' => 'finalized', 'currency' => 'USD', 'total_minor' => 700, 'due_at' => $now->subDays(2), 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => 10, 'customer_id' => 5, 'status' => 'open', 'currency' => 'USD', 'total_minor' => 300, 'due_at' => $now->addDays(5), 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => 10, 'customer_id' => 5, 'status' => 'finalized', 'currency' => 'EUR', 'total_minor' => 999, 'due_at' => $now->subDays(2), 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('billing_payments')->insert([
        ['team_id' => 10, 'customer_id' => 5, 'status' => 'captured', 'currency' => 'USD', 'amount_minor' => 1000, 'refunded_minor' => 150, 'gateway' => 'test', 'created_at' => $now, 'updated_at' => $now],
        ['team_id' => 10, 'customer_id' => 5, 'status' => 'captured', 'currency' => 'EUR', 'amount_minor' => 500, 'refunded_minor' => 0, 'gateway' => 'test', 'created_at' => $now, 'updated_at' => $now],
    ]);

    expect($action->execute(10, 5, 'usd'))->toBe([
        'customer_id' => 5,
        'total_invoiced' => 2000,
        'total_paid' => 850,
        'total_outstanding' => 1000,
        'overdue_amount' => 700,
        'currency' => 'USD',
    ]);
});

it('validates and records reporting metrics', function (): void {
    $recorder = app(RecordReportingMetric::class);

    expect(fn () => $recorder->execute(0, ['metric' => 'revenue', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'value' => 10]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $recorder->execute(10, ['metric' => 'revenue', 'period_start' => 'not-a-date', 'period_end' => '2026-08-31', 'value' => 10]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $recorder->execute(10, ['metric' => 'revenue', 'period_start' => '2026-08-31', 'period_end' => '2026-08-01', 'value' => 10]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $recorder->execute(10, ['metric' => 'revenue', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'value' => 10, 'currency' => 'US']))
        ->toThrow(InvalidArgumentException::class);

    $metric = $recorder->execute(10, [
        'metric' => 'revenue',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'value' => 1500,
        'currency' => 'usd',
        'source' => 'manual',
    ]);

    expect($metric->currency)->toBe('USD');
});

it('queries team-scoped metric snapshots and reporting metrics', function (): void {
    $createSnapshot = app(CreateMetricSnapshot::class);
    $snap1 = $createSnapshot->handle(10, ['name' => 'Q3 Snapshot', 'status' => 'ready']);
    $createSnapshot->handle(11, ['name' => 'Other Team Snapshot']);

    $recorder = app(RecordReportingMetric::class);
    $m1 = $recorder->execute(10, ['metric' => 'revenue', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'value' => 100]);
    $recorder->execute(10, ['metric' => 'tax', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'value' => 20]);
    $recorder->execute(11, ['metric' => 'revenue', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'value' => 999]);

    $paginatedSnapshots = app(ListMetricSnapshots::class)->execute(10, 10);
    $listedRecords = app(ListReportingRecords::class)->handle(10);
    $allTeamMetrics = app(ListReportingMetrics::class)->execute(10);
    $filteredMetrics = app(ListReportingMetrics::class)->execute(10, 'revenue', 10);

    expect($paginatedSnapshots->pluck('id')->all())->toBe([$snap1->id])
        ->and($listedRecords->pluck('id')->all())->toBe([$snap1->id])
        ->and($allTeamMetrics->total())->toBe(2)
        ->and($filteredMetrics->pluck('id')->all())->toBe([$m1->id]);
});

it('enforces MetricSnapshotPolicy and ReportingMetricPolicy', function (): void {
    $snapshotPolicy = new MetricSnapshotPolicy();
    $metricPolicy = new ReportingMetricPolicy();

    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $teamId = (int) $user->current_team_id;

    $ownSnapshot = app(CreateMetricSnapshot::class)->handle($teamId, ['name' => 'Own']);
    $otherSnapshot = app(CreateMetricSnapshot::class)->handle($teamId + 1, ['name' => 'Other']);
    $ownMetric = app(RecordReportingMetric::class)->execute($teamId, ['metric' => 'mrr', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'value' => 10]);
    $otherMetric = app(RecordReportingMetric::class)->execute($teamId + 1, ['metric' => 'mrr', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'value' => 10]);

    $genericUser = new GenericUser(['id' => 1, 'current_team_id' => $teamId]);

    expect($snapshotPolicy->viewAny(null))->toBeFalse()
        ->and($snapshotPolicy->viewAny($genericUser))->toBeTrue()
        ->and($snapshotPolicy->create($genericUser))->toBeTrue()
        ->and($snapshotPolicy->view($genericUser, $ownSnapshot))->toBeTrue()
        ->and($snapshotPolicy->view($genericUser, $otherSnapshot))->toBeFalse()
        ->and($snapshotPolicy->update($genericUser, $ownSnapshot))->toBeTrue()
        ->and($snapshotPolicy->update($genericUser, $otherSnapshot))->toBeFalse()
        ->and($metricPolicy->viewAny(null))->toBeFalse()
        ->and($metricPolicy->viewAny($genericUser))->toBeTrue()
        ->and($metricPolicy->create($genericUser))->toBeTrue()
        ->and($metricPolicy->view($genericUser, $ownMetric))->toBeTrue()
        ->and($metricPolicy->view($genericUser, $otherMetric))->toBeFalse();

    Sanctum::actingAs($user, ['billing.reporting.read']);
    expect($snapshotPolicy->viewAny($user))->toBeTrue()
        ->and($snapshotPolicy->create($user))->toBeFalse()
        ->and($snapshotPolicy->view($user, $ownSnapshot))->toBeTrue()
        ->and($snapshotPolicy->update($user, $ownSnapshot))->toBeFalse()
        ->and($metricPolicy->viewAny($user))->toBeTrue()
        ->and($metricPolicy->create($user))->toBeFalse()
        ->and($metricPolicy->view($user, $ownMetric))->toBeTrue();

    Sanctum::actingAs($user, ['billing.reporting.write']);
    expect($snapshotPolicy->create($user))->toBeTrue()
        ->and($snapshotPolicy->update($user, $ownSnapshot))->toBeTrue()
        ->and($metricPolicy->create($user))->toBeTrue();
});
