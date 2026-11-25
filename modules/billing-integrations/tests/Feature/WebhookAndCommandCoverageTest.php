<?php

declare(strict_types=1);

use App\Console\Commands\ProcessWebhooks;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Services\WebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    Gate::before(fn (): true => true);
});

function webhookOperator(): array
{
    $user = User::factory()->withPersonalTeam()->create();
    Sanctum::actingAs($user, ['webhooks:manage']);

    return [$user, $user->currentTeam];
}

it('manages webhook endpoints through store, update, destroy, events, event types, test, and retry endpoints', function (): void {
    [, $team] = webhookOperator();

    // Reject unsafe SSRF URL during store
    $this->postJson('/api/webhooks', [
        'url' => 'https://127.0.0.1/webhook',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['url']);

    // Store a valid webhook endpoint
    $created = $this->postJson('/api/webhooks', [
        'url' => 'https://203.0.113.10/webhook',
        'secret' => 'endpoint-signing-secret',
        'events' => [WebhookService::EVENT_INVOICE_CREATED],
        'description' => 'Billing notifications',
        'max_retries' => 3,
        'retry_interval' => 60,
    ])->assertCreated()
        ->assertJsonPath('message', 'Webhook endpoint created successfully')
        ->assertJsonPath('data.team_id', $team->id);

    $endpointId = $created->json('data.id');

    // Update the webhook endpoint
    $this->patchJson("/api/webhooks/{$endpointId}", [
        'url' => 'https://203.0.113.11/webhook',
        'description' => 'Updated billing notifications',
        'is_active' => true,
        'max_retries' => 2,
        'retry_interval' => 30,
    ])->assertOk()
        ->assertJsonPath('message', 'Webhook endpoint updated successfully')
        ->assertJsonPath('data.description', 'Updated billing notifications');

    // Event types list
    $this->getJson('/api/webhook-event-types')
        ->assertOk()
        ->assertJsonPath('data.'.WebhookService::EVENT_INVOICE_CREATED, 'Invoice Created');

    // Test endpoint (success)
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->postJson("/api/webhooks/{$endpointId}/test")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Test webhook sent successfully');

    // Test endpoint (failure)
    Http::fake(['*' => Http::response('error', 500)]);
    $this->postJson("/api/webhooks/{$endpointId}/test")
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Test webhook failed');

    // List events for endpoint
    $this->getJson("/api/webhooks/{$endpointId}/events")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $sentEvent = WebhookEvent::query()
        ->where('webhook_endpoint_id', $endpointId)
        ->where('status', 'sent')
        ->firstOrFail();

    $failedEvent = WebhookEvent::query()
        ->where('webhook_endpoint_id', $endpointId)
        ->where('status', 'failed')
        ->firstOrFail();

    // Retry an already-sent event returns 400
    $this->postJson("/api/webhook-events/{$sentEvent->id}/retry")
        ->assertStatus(400)
        ->assertJsonPath('message', 'Event has already been sent successfully');

    // Retry a failed event succeeds
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->postJson("/api/webhook-events/{$failedEvent->id}/retry")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Event resent successfully');

    // Delete the endpoint
    $this->deleteJson("/api/webhooks/{$endpointId}")
        ->assertOk()
        ->assertJsonPath('message', 'Webhook endpoint deleted successfully');

    $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpointId]);
});

it('covers webhook endpoint team relation and wildcard subscription when events are empty', function (): void {
    $team = Team::factory()->create();
    $endpoint = WebhookEndpoint::create([
        'team_id' => $team->id,
        'url' => 'https://203.0.113.10/wildcard',
        'is_active' => true,
        'events' => [],
    ]);

    expect($endpoint->team?->id)->toBe($team->id)
        ->and($endpoint->isSubscribedTo(WebhookService::EVENT_PAYMENT_RECEIVED))->toBeTrue();
});

it('covers team-scoped dispatch, unresolvable host rejection, and exhausted retries in WebhookService', function (): void {
    $teamA = Team::factory()->create();
    $teamB = Team::factory()->create();

    $endpointA = WebhookEndpoint::create([
        'team_id' => $teamA->id,
        'url' => 'https://203.0.113.20/hook-a',
        'is_active' => true,
        'events' => [WebhookService::EVENT_INVOICE_PAID],
        'max_retries' => 0,
        'retry_interval' => 60,
    ]);

    WebhookEndpoint::create([
        'team_id' => $teamB->id,
        'url' => 'https://203.0.113.21/hook-b',
        'is_active' => true,
        'events' => [WebhookService::EVENT_INVOICE_PAID],
    ]);

    $service = new WebhookService();
    $service->dispatch(WebhookService::EVENT_INVOICE_PAID, ['invoice_id' => 99], $teamA->id);

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->first()->webhook_endpoint_id)->toBe($endpointA->id);

    // Unresolvable host (.invalid TLD per RFC 2606)
    expect(fn () => WebhookService::assertSafeUrl('https://nonexistent.invalid/webhook'))
        ->toThrow(Exception::class, 'Webhook URL host could not be resolved');

    // Exhausted retries branch (max_retries = 0 -> shouldRetry returns false)
    Http::fake(['*' => Http::response('gateway error', 502)]);
    $event = WebhookEvent::query()->firstOrFail();

    expect($service->send($event))->toBeFalse();

    $event->refresh();
    expect($event->status)->toBe('failed')
        ->and($event->attempts)->toBe(1)
        ->and($event->next_retry_at)->toBeNull();
});

it('handles lock contention and unexpected exceptions in ProcessWebhooks command', function (): void {
    Cache::put('processing_webhooks', true, 60);

    try {
        $this->artisan('webhooks:process')
            ->expectsOutput('Webhook processing is already running')
            ->assertExitCode(Command::FAILURE);
    } finally {
        Cache::forget('processing_webhooks');
    }

    $failingService = Mockery::mock(WebhookService::class);
    $failingService->shouldReceive('processPending')
        ->once()
        ->andThrow(new Exception('Queue backend offline'));

    $command = new ProcessWebhooks($failingService);
    $command->setLaravel($this->app);
    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Error processing webhooks: Queue backend offline')
        ->and(Cache::get('processing_webhooks'))->toBeNull();
});
