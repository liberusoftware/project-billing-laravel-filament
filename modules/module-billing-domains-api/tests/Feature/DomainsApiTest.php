<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Domains\Api\Http\Controllers\DomainController;
use Liberu\Billing\Domains\Contracts\RegistrarClient;
use Liberu\Billing\Domains\Services\RegistrarManager;

it('manages domains, TLDs, contacts, DNS records, EPP operations, and registrar actions via API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    Sanctum::actingAs($user, ['billing.domains.read', 'billing.domains.write']);

    $customerId = DB::table('customers')->insertGetId([
        'team_id' => $team->id,
        'name' => 'API Domain Customer',
        'email' => 'api-domain-customer@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(RegistrarManager::class)->register('api-reg', new class implements RegistrarClient
    {
        public function registerDomain(string $domainName, mixed $customerId): ?array
        {
            return ['expiration_date' => now()->addYear(), 'epp_code' => '1000'];
        }

        public function renewDomain(string $domainName, int $period): ?array
        {
            return ['new_expiration_date' => now()->addYears($period), 'epp_code' => '1000'];
        }

        public function transferDomain(string $domainName, string $authCode, mixed $customerId): ?array
        {
            return ['expiration_date' => now()->addYear(), 'epp_code' => '1001'];
        }

        public function checkAvailability(string $domainName): bool
        {
            return true;
        }

        public function getAvailableTlds(): array
        {
            return ['.com', '.org'];
        }

        public function getDomainPrice(string $tld): float
        {
            return 11.50;
        }

        public function getDnsRecords(string $domainName): array
        {
            return [];
        }

        public function addDnsRecord(string $domainName, array $record): bool
        {
            return true;
        }

        public function deleteDnsRecord(string $domainName, string $recordId): bool
        {
            return true;
        }

        public function getWhoisContacts(string $domainName): array
        {
            return [];
        }

        public function updateWhoisContacts(string $domainName, array $contacts): bool
        {
            return true;
        }
    });

    // TLD store, sync, and list
    $this->postJson('/api/v1/billing/domains/tlds', [
        'name' => 'io',
        'registrar_cost' => 15.0,
        'base_price' => 20.0,
        'markup_type' => 'fixed',
        'markup_value' => 5.0,
        'enabled' => true,
    ])->assertCreated()
        ->assertJsonPath('data.type', 'domain-tld')
        ->assertJsonPath('data.attributes.name', '.io');

    $this->postJson('/api/v1/billing/domains/tlds/sync', [
        'registrar' => 'api-reg',
        'markup_value' => 15,
    ])->assertOk()
        ->assertJsonPath('synchronized', 2);

    $this->getJson('/api/v1/billing/domains/tlds')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    // Domain search
    $this->getJson('/api/v1/billing/domains/search?domain=api-search.com&registrar=api-reg')
        ->assertOk()
        ->assertJsonPath('domain', 'api-search.com')
        ->assertJsonPath('available', true);

    // Domain CRUD
    $domainId = $this->postJson('/api/v1/billing/domains', [
        'name' => 'api-example.com',
        'status' => 'active',
        'registrar' => 'api-reg',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'domain')
        ->assertJsonPath('data.attributes.name', 'api-example.com')
        ->json('data.id');

    $this->getJson('/api/v1/billing/domains?per_page=10')
        ->assertOk()
        ->assertJsonPath('data.0.id', $domainId)
        ->assertJsonPath('meta.total', 1);

    $this->getJson("/api/v1/billing/domains/{$domainId}")
        ->assertOk()
        ->assertJsonPath('data.id', $domainId);

    $this->patchJson("/api/v1/billing/domains/{$domainId}", [
        'status' => 'pending',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'pending');

    // Register, renew, transfer, redeem
    $this->postJson("/api/v1/billing/domains/{$domainId}/register", [
        'customer_id' => $customerId,
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'registered');

    $this->postJson("/api/v1/billing/domains/{$domainId}/renew", [
        'period' => 2,
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'registered');

    $this->postJson("/api/v1/billing/domains/{$domainId}/transfer", [
        'auth_code' => 'EPP-API-1',
        'customer_id' => $customerId,
        'registrar' => 'api-reg',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'transfer_pending');

    $this->postJson("/api/v1/billing/domains/{$domainId}/redeem")
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'redemption_pending');

    // Contacts
    $this->postJson('/api/v1/billing/domains/contacts', [
        'handle' => 'API-CON-1',
        'name' => 'API Contact',
        'email' => 'contact@example.com',
        'details' => ['country' => 'US'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'domain-contact');

    $this->getJson('/api/v1/billing/domains/contacts?per_page=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    // DNS records
    $this->postJson("/api/v1/billing/domains/{$domainId}/dns", [
        'type' => 'A',
        'host' => '@',
        'value' => '192.0.2.1',
        'ttl' => 3600,
    ])->assertCreated()
        ->assertJsonPath('data.type', 'dns-record');

    $this->getJson("/api/v1/billing/domains/{$domainId}/dns")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // EPP operations
    $this->getJson('/api/v1/billing/domains/epp-operations?per_page=10')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'epp-operation');

    // Destroy domain
    $this->deleteJson("/api/v1/billing/domains/{$domainId}")
        ->assertNoContent();

    // Default match arm in DomainController::resource
    $controller = new DomainController();
    $resourceMethod = new ReflectionMethod($controller, 'resource');
    expect($resourceMethod->invoke($controller, $user))->toHaveKey('attributes', []);
});
