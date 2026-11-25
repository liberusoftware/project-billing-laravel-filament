<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Reporting\Actions\CreateMetricSnapshot;
use Liberu\Billing\Reporting\Actions\RecordReportingMetric;
use Liberu\Billing\Reporting\Api\Http\Controllers\ReportingMetricController;

uses(RefreshDatabase::class);

it('runs the full billing reporting API lifecycle', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $teamId = (int) $user->current_team_id;
    Sanctum::actingAs($user, ['billing.reporting.read', 'billing.reporting.write']);

    $recordedId = $this->postJson(route('billing.reporting.metrics.store'), [
        'metric' => 'revenue',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'value' => 1500,
        'currency' => 'USD',
        'source' => 'api-test',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-reporting-metric')
        ->assertJsonPath('data.attributes.metric', 'revenue')
        ->json('data.id');

    $this->getJson(route('billing.reporting.metrics.index', ['metric' => 'revenue', 'per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $recordedId)
        ->assertJsonPath('meta.total', 1);

    $this->get(route('billing.reporting.metrics.export', ['metric' => 'revenue']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertSee('revenue,2026-08-01,2026-08-31');

    $this->postJson(route('billing.reporting.metrics.calculate'), [
        'metric' => 'mrr',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'currency' => 'USD',
    ])->assertCreated()
        ->assertJsonPath('data.attributes.metric', 'mrr');

    $this->getJson(route('billing.reporting.customers.summary', ['customer' => 5, 'currency' => 'USD']))
        ->assertOk()
        ->assertJsonPath('data.customer_id', 5)
        ->assertJsonPath('data.currency', 'USD');

    $snapshotId = $this->postJson(route('billing.reporting.snapshots.store'), [
        'name' => 'August Snapshot',
        'status' => 'ready',
        'metadata' => ['region' => 'eu'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-reporting-snapshot')
        ->assertJsonPath('data.attributes.name', 'August Snapshot')
        ->json('data.id');

    $otherTeamSnapshot = app(CreateMetricSnapshot::class)->handle($teamId + 1, ['name' => 'Foreign']);

    $this->getJson(route('billing.reporting.snapshots.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $snapshotId);

    $this->getJson(route('billing.reporting.snapshots.show', $snapshotId))
        ->assertOk()
        ->assertJsonPath('data.id', $snapshotId);

    $this->getJson(route('billing.reporting.snapshots.show', $otherTeamSnapshot->id))
        ->assertNotFound();
});

it('rejects reporting API calls when the user has no current team', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['billing.reporting.read']);

    $this->getJson(route('billing.reporting.metrics.index'))
        ->assertForbidden();
});

it('handles index and store directly on ReportingMetricController', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.reporting.read', 'billing.reporting.write']);

    $controller = app(ReportingMetricController::class);

    $storeRequest = Request::create('/metrics', 'POST', [
        'metric' => 'tax',
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'value' => 250,
        'currency' => 'USD',
        'source' => 'controller-test',
    ]);
    $storeRequest->setUserResolver(static fn () => $user);

    $storeResponse = $controller->store($storeRequest, app(RecordReportingMetric::class));
    expect($storeResponse->getStatusCode())->toBe(201)
        ->and($storeResponse->getData(true)['data']['attributes']['metric'])->toBe('tax');

    $indexRequest = Request::create('/metrics', 'GET', ['metric' => 'tax', 'per_page' => 10]);
    $indexRequest->setUserResolver(static fn () => $user);

    $indexResponse = $controller->index($indexRequest);
    expect($indexResponse->getStatusCode())->toBe(200)
        ->and($indexResponse->getData(true)['meta']['total'])->toBe(1)
        ->and($indexResponse->getData(true)['data'][0]['attributes']['metric'])->toBe('tax');
});
