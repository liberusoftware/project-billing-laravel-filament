<?php

declare(strict_types=1);

use App\Contracts\BusinessConnector;
use App\Enums\ConnectorType;
use App\Jobs\PublishDomainEvent;
use App\Models\Customer;
use App\Models\DomainEventDelivery;
use App\Models\DomainEventMessage;
use App\Models\ExternalConnection;
use App\Models\ExternalSyncRecord;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\TaxRate;
use App\Models\Team;
use App\Models\User;
use App\Services\DomainEventBus;
use App\Services\Integrations\HttpBusinessConnector;
use App\Services\IntegrationService;
use App\Services\IntegrationSyncService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

function integrationOperator(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->update(['current_team_id' => $team->id]);
    Sanctum::actingAs($user, ['integrations:read', 'integrations:write']);

    return [$user, $team];
}

it('covers all CrossPlatformIntegrationController endpoints', function (): void {
    Queue::fake();
    [, $team] = integrationOperator();

    $crm = ExternalConnection::factory()->create([
        'team_id' => $team->id,
        'connector_type' => ConnectorType::Crm,
        'provider' => 'acme-crm',
        'name' => 'Primary CRM',
        'base_url' => 'https://crm.example.test/api',
        'is_active' => true,
    ]);

    $accounting = ExternalConnection::factory()->create([
        'team_id' => $team->id,
        'connector_type' => ConnectorType::Accounting,
        'provider' => 'acme-books',
        'name' => 'Primary Accounting',
        'base_url' => 'https://books.example.test/api',
        'is_active' => true,
    ]);

    // Index connections
    $this->getJson('/api/integrations/connections')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Update connection
    $this->patchJson("/api/integrations/connections/{$crm->id}", [
        'name' => 'Updated CRM',
        'is_active' => true,
    ])->assertOk()
        ->assertJsonPath('name', 'Updated CRM');

    // Push resources: customers, invoices, payments, tax-rates, and unsupported
    Http::fake(['*' => Http::response(['id' => 'remote-100'], 201)]);

    $customer = Customer::factory()->create([
        'team_id' => $team->id,
        'lifecycle_status' => 'active',
    ]);
    $invoice = Invoice::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'currency' => 'USD',
    ]);
    $payment = Payment::factory()->create([
        'team_id' => $team->id,
        'invoice_id' => $invoice->id,
        'status' => 'completed',
        'payment_date' => now(),
    ]);
    $taxRate = TaxRate::query()->create([
        'team_id' => $team->id,
        'name' => 'UK VAT',
        'country' => 'GB',
        'state' => null,
        'rate' => 20.00,
        'is_active' => true,
    ]);

    $this->postJson("/api/integrations/sync/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('connections_synchronized', 1);

    $this->postJson("/api/integrations/sync/invoices/{$invoice->id}")
        ->assertOk()
        ->assertJsonPath('connections_synchronized', 1);

    $this->postJson("/api/integrations/sync/payments/{$payment->id}")
        ->assertOk()
        ->assertJsonPath('connections_synchronized', 1);

    $this->postJson("/api/integrations/sync/tax-rates/{$taxRate->id}")
        ->assertOk()
        ->assertJsonPath('connections_synchronized', 1);

    $this->postJson('/api/integrations/sync/unsupported/999')
        ->assertStatus(500);

    // Pull resource (valid & invalid)
    Http::swap(new HttpFactory());
    Http::fake([
        '*' => Http::response([
            'items' => [['id' => 'ext-1']],
            'next_cursor' => 'cursor-2',
        ]),
    ]);

    $this->getJson("/api/integrations/connections/{$accounting->id}/pull/invoices?cursor=cursor-1")
        ->assertOk()
        ->assertJsonPath('items.0.id', 'ext-1')
        ->assertJsonPath('cursor', 'cursor-2');

    $this->getJson("/api/integrations/connections/{$accounting->id}/pull/unknown")
        ->assertNotFound();

    // Convert lead
    Http::swap(new HttpFactory());
    Http::fake(['*' => Http::response(['id' => 'remote-lead-cust'], 201)]);
    $lead = Lead::query()->create([
        'team_id' => $team->id,
        'name' => 'New Prospect',
        'email' => 'lead@example.test',
        'status' => 'new',
    ]);

    $this->postJson("/api/integrations/leads/{$lead->id}/convert")
        ->assertCreated()
        ->assertJsonPath('name', 'New Prospect');

    // Bill opportunity
    $this->postJson("/api/integrations/customers/{$customer->id}/bill-opportunity", [
        'amount' => 450.50,
        'currency' => 'USD',
        'name' => 'Enterprise upgrade',
    ])->assertCreated()
        ->assertJsonPath('customer_id', $customer->id);

    // Event log & replay event
    $event = DomainEventMessage::query()->create([
        'team_id' => $team->id,
        'event_name' => 'invoice.created',
        'aggregate_type' => 'invoice',
        'aggregate_id' => (string) $invoice->id,
        'payload' => ['invoice_id' => $invoice->id],
        'status' => 'failed',
        'occurred_at' => now(),
    ]);

    $this->getJson('/api/integrations/events')
        ->assertOk()
        ->assertJsonPath('data.0.id', $event->id);

    $this->postJson("/api/integrations/events/{$event->id}/replay")
        ->assertAccepted()
        ->assertJsonPath('status', 'pending');

    Queue::assertPushed(PublishDomainEvent::class, fn ($job): bool => $job->eventId === $event->id);

    // Destroy connection
    $this->deleteJson("/api/integrations/connections/{$crm->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('external_connections', ['id' => $crm->id]);
});

it('rejects inactive connection push in IntegrationSyncService', function (): void {
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $inactive = ExternalConnection::factory()->create([
        'team_id' => $team->id,
        'is_active' => false,
    ]);

    $service = app(IntegrationSyncService::class);

    expect(fn () => $service->push($inactive, $customer, 'customers', ['name' => $customer->name]))
        ->toThrow(InvalidArgumentException::class, 'The external connection is inactive.');
});

it('covers HttpBusinessConnector error paths', function (): void {
    $connection = ExternalConnection::factory()->create([
        'provider' => 'acme',
        'base_url' => 'https://connector.example.test/api',
    ]);
    $connector = app(HttpBusinessConnector::class);

    // push() response without id or data.id
    Http::fake(['*' => Http::response(['status' => 'ok'], 201)]);
    expect(fn () => $connector->push($connection, 'customers', '1', ['name' => 'Acme']))
        ->toThrow(RuntimeException::class, 'The connector response did not contain a resource identifier.');

    // pull() response not an array
    Http::swap(new HttpFactory());
    Http::fake(['*' => Http::response('"scalar"', 200, ['Content-Type' => 'application/json'])]);
    expect(fn () => $connector->pull($connection, 'customers'))
        ->toThrow(RuntimeException::class, 'The connector returned an invalid response.');

    // pull() items not an array
    Http::swap(new HttpFactory());
    Http::fake(['*' => Http::response(['items' => 'bad-items'], 200)]);
    expect(fn () => $connector->pull($connection, 'customers'))
        ->toThrow(RuntimeException::class, 'The connector returned an invalid resource collection.');

    // send() HTTP 500 failure
    Http::swap(new HttpFactory());
    Http::fake(['*' => Http::response('Internal Error', 500)]);
    expect(fn () => $connector->pull($connection, 'customers'))
        ->toThrow(RuntimeException::class, 'acme connector operation failed.');
});

it('marks delivery and event as failed when PublishDomainEvent throws', function (): void {
    Queue::fake();
    $team = Team::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $connection = ExternalConnection::factory()->create([
        'team_id' => $team->id,
        'connector_type' => ConnectorType::EventBus,
        'event_subscriptions' => ['*'],
    ]);

    $event = app(DomainEventBus::class)->record(
        $team->id,
        'customer.created',
        $customer,
        ['id' => $customer->id]
    );

    $failingConnector = new class() implements BusinessConnector
    {
        public function push(ExternalConnection $connection, string $resource, string $localId, array $payload, ?string $remoteId = null): string
        {
            return 'unused';
        }

        public function pull(ExternalConnection $connection, string $resource, ?string $cursor = null): array
        {
            return ['items' => [], 'cursor' => null];
        }

        public function publish(ExternalConnection $connection, DomainEventMessage $event): void
        {
            throw new RuntimeException('Remote event bus unreachable');
        }
    };

    (new PublishDomainEvent($event->id))->handle($failingConnector);

    $event->refresh();
    $delivery = DomainEventDelivery::query()->where('domain_event_message_id', $event->id)->firstOrFail();

    expect($event->status)->toBe('failed')
        ->and($event->published_at)->toBeNull()
        ->and($event->last_error)->toBe('One or more event deliveries failed.')
        ->and($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_error)->toBe('Event delivery failed.')
        ->and($delivery->event->id)->toBe($event->id)
        ->and($connection->eventDeliveries()->count())->toBe(1);

    $syncRecord = ExternalSyncRecord::query()->create([
        'external_connection_id' => $connection->id,
        'resource_type' => 'customers',
        'local_id' => (string) $customer->id,
        'remote_id' => 'remote-1',
        'status' => 'synchronized',
    ]);
    expect($syncRecord->connection->id)->toBe($connection->id);
});

it('covers IntegrationService and Integration model', function (): void {
    $user = User::factory()->create();
    $service = new IntegrationService();

    $providers = $service->getProviders();
    expect($providers)->toHaveKeys(['google', 'slack', 'trello']);

    $integration = $service->connect('slack', $user, [
        'token' => 'xoxb-secret-token',
        'refresh_token' => 'xoxr-refresh-token',
        'expires_in' => 3600,
        'scopes' => ['channels:read', 'chat:write'],
        'settings' => ['channel' => '#billing'],
    ]);

    expect($integration)->toBeInstanceOf(Integration::class)
        ->and($integration->user->id)->toBe($user->id)
        ->and($integration->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($integration->scopes)->toBe(['channels:read', 'chat:write'])
        ->and($integration->settings)->toBe(['channel' => '#billing']);

    expect($service->disconnect('slack', $user))->toBe(1);
    $this->assertDatabaseMissing('integrations', ['id' => $integration->id]);
});
