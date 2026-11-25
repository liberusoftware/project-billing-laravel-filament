<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\InvoiceStatusChanged;
use App\Filament\Client\Pages\DomainManagement;
use App\Filament\Client\Widgets\ExpiringDomains;
use App\Listeners\RenewDomainOnPayment;
use App\Models\Customer;
use App\Models\DomainRenewal;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\DomainPricingService;
use App\Services\DomainService;
use App\Services\Registrars\EnomClient;
use App\Services\Registrars\ResellerClubClient;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BillingDomainsChunk8CoverageTest extends TestCase
{
    use RefreshDatabase;

    private function bootClientPanel(User $user): void
    {
        $this->actingAs($user);
        $panel = Filament::getPanel('client');
        Filament::setCurrentPanel($panel);
        $panel->boot();
    }

    public function test_process_domain_renewals_marks_renewal_failed_when_registrar_throws(): void
    {
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'domain_name' => 'failrenew-chunk8.com',
            'domain_registrar' => 'enom',
        ]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'status' => 'paid',
        ]);
        $renewal = DomainRenewal::create([
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription->id,
            'domain' => 'failrenew-chunk8.com',
            'registrar' => 'enom',
            'status' => 'pending',
        ]);

        $domainService = Mockery::mock(DomainService::class);
        $domainService->shouldReceive('renewDomain')
            ->once()
            ->andThrow(new RuntimeException('Registrar timeout'));
        $this->app->instance(DomainService::class, $domainService);

        $this->artisan('domains:process-renewals')
            ->expectsOutputToContain('Registrar timeout')
            ->assertSuccessful();

        $renewal->refresh();
        $this->assertSame('failed', $renewal->status);
        $this->assertSame('Registrar timeout', $renewal->last_error);
        $this->assertSame(1, $renewal->attempts);
    }

    public function test_sync_domains_logs_error_when_registrar_throws_exception(): void
    {
        $customer = Customer::factory()->create();
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'domain_name' => 'broken-sync-chunk8.com',
            'domain_registrar' => 'enom',
        ]);

        $enom = Mockery::mock(EnomClient::class);
        $enom->shouldReceive('getDomainExpiration')
            ->with('broken-sync-chunk8.com')
            ->once()
            ->andThrow(new RuntimeException('API unreachable'));
        $this->app->instance(EnomClient::class, $enom);

        $this->artisan('domains:sync')
            ->expectsOutputToContain('Failed to sync broken-sync-chunk8.com: API unreachable')
            ->expectsOutputToContain('Domain synchronization complete.')
            ->assertSuccessful();
    }

    public function test_sync_enom_domains_command_succeeds(): void
    {
        $pricing = Mockery::mock(DomainPricingService::class);
        $pricing->shouldReceive('syncTldsFromEnom')->once();
        $this->app->instance(DomainPricingService::class, $pricing);

        $this->artisan('enom:sync-domains')
            ->expectsOutputToContain('Starting Enom domain synchronization...')
            ->expectsOutputToContain('Enom domain synchronization completed successfully.')
            ->assertSuccessful();
    }

    public function test_sync_enom_domains_command_handles_exception(): void
    {
        $pricing = Mockery::mock(DomainPricingService::class);
        $pricing->shouldReceive('syncTldsFromEnom')
            ->once()
            ->andThrow(new Exception('Enom down'));
        $this->app->instance(DomainPricingService::class, $pricing);

        $this->artisan('enom:sync-domains')
            ->expectsOutputToContain('Starting Enom domain synchronization...')
            ->expectsOutputToContain('An error occurred during Enom domain synchronization: Enom down')
            ->assertSuccessful();
    }

    public function test_renew_domain_on_payment_returns_early_for_non_paid_status(): void
    {
        $customer = Customer::factory()->create();
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);

        (new RenewDomainOnPayment)->handle(new InvoiceStatusChanged($invoice, 'pending'));

        $this->assertDatabaseCount('domain_renewals', 0);
    }

    public function test_domain_pricing_service_syncs_tlds_from_enom(): void
    {
        $enom = Mockery::mock(EnomClient::class);
        $enom->shouldReceive('getAvailableTlds')->once()->andReturn(['.com', '.net']);
        $enom->shouldReceive('getDomainPrice')->with('.com')->once()->andReturn(10.50);
        $enom->shouldReceive('getDomainPrice')->with('.net')->once()->andReturn(12.00);

        $service = new DomainPricingService($enom);
        $service->syncTldsFromEnom();

        $this->assertDatabaseHas('tlds', [
            'name' => '.com',
            'enom_cost' => 10.50,
            'base_price' => 10.50,
            'markup_type' => 'percentage',
            'markup_value' => 10,
        ]);
        $this->assertDatabaseHas('tlds', [
            'name' => '.net',
            'enom_cost' => 12.00,
            'base_price' => 12.00,
            'markup_type' => 'percentage',
            'markup_value' => 10,
        ]);
    }

    public function test_enom_client_gets_available_tlds(): void
    {
        config([
            'services.enom.api_url' => 'https://enom.example.test/interface.asp',
            'services.enom.username' => 'user',
            'services.enom.password' => 'pass',
        ]);

        Http::fake([
            '*' => Http::response(
                '<interface-response><ErrCount>0</ErrCount><tldlist><tld><tld>.com</tld></tld><tld><tld>.org</tld></tld></tldlist></interface-response>',
                200
            ),
        ]);

        $client = new EnomClient;
        $this->assertSame(['.com', '.org'], $client->getAvailableTlds());
    }

    public function test_enom_client_gets_domain_price(): void
    {
        config([
            'services.enom.api_url' => 'https://enom.example.test/interface.asp',
            'services.enom.username' => 'user',
            'services.enom.password' => 'pass',
        ]);

        Http::fake([
            '*' => Http::response(
                '<interface-response><ErrCount>0</ErrCount><price>11.25</price></interface-response>',
                200
            ),
        ]);

        $client = new EnomClient;
        $this->assertSame(11.25, $client->getDomainPrice('.com'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'command=PE_GetDomainPrice')
            && str_contains($request->url(), 'TLD=com'));
    }

    public function test_enom_client_deletes_dns_record(): void
    {
        config([
            'services.enom.api_url' => 'https://enom.example.test/interface.asp',
            'services.enom.username' => 'user',
            'services.enom.password' => 'pass',
        ]);

        Http::fake([
            '*' => Http::response('<interface-response><ErrCount>0</ErrCount></interface-response>', 200),
        ]);

        $client = new EnomClient;
        $this->assertTrue($client->deleteDnsRecord('example.com', '42'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'command=SetHosts')
            && str_contains($request->url(), 'DeleteHostId=42'));
    }

    public function test_enom_client_gets_whois_contacts(): void
    {
        config([
            'services.enom.api_url' => 'https://enom.example.test/interface.asp',
            'services.enom.username' => 'user',
            'services.enom.password' => 'pass',
        ]);

        Http::fake([
            '*' => Http::response(
                '<interface-response><ErrCount>0</ErrCount><contacts><contact><ContactType>Registrant</ContactType><FirstName>Jane</FirstName><LastName>Doe</LastName><EmailAddress>jane@example.com</EmailAddress></contact></contacts></interface-response>',
                200
            ),
        ]);

        $client = new EnomClient;
        $contacts = $client->getWhoisContacts('example.com');

        $this->assertSame([
            'Registrant' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane@example.com',
            ],
        ], $contacts);
    }

    public function test_enom_client_throws_on_http_failure(): void
    {
        config([
            'services.enom.api_url' => 'https://enom.example.test/interface.asp',
            'services.enom.username' => 'user',
            'services.enom.password' => 'pass',
        ]);

        Http::fake([
            '*' => Http::response('Server error', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('eNom API request failed.');

        (new EnomClient)->getAvailableTlds();
    }

    public function test_enom_client_throws_on_doctype_response(): void
    {
        config([
            'services.enom.api_url' => 'https://enom.example.test/interface.asp',
            'services.enom.username' => 'user',
            'services.enom.password' => 'pass',
        ]);

        Http::fake([
            '*' => Http::response('<!DOCTYPE foo><interface-response/>', 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid eNom API response.');

        (new EnomClient)->getAvailableTlds();
    }

    public function test_enom_client_throws_on_unexpected_xml_root(): void
    {
        config([
            'services.enom.api_url' => 'https://enom.example.test/interface.asp',
            'services.enom.username' => 'user',
            'services.enom.password' => 'pass',
        ]);

        Http::fake([
            '*' => Http::response('<unexpected-root/>', 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid eNom API response.');

        (new EnomClient)->getAvailableTlds();
    }

    public function test_reseller_club_client_stub_methods(): void
    {
        config([
            'services.resellerclub.api_url' => 'https://rc.example.test/api',
            'services.resellerclub.auth_userid' => '123',
            'services.resellerclub.api_key' => 'secret',
        ]);

        $client = new ResellerClubClient;

        $this->assertSame(['new_expiration_date' => null], $client->renewDomain('example.com', 1));
        $this->assertSame(['expiration_date' => null], $client->transferDomain('example.com', 'AUTH', 1));
        $this->assertSame([], $client->getAvailableTlds());
        $this->assertSame(0.0, $client->getDomainPrice('.com'));
        $this->assertSame([], $client->getDnsRecords('example.com'));
        $this->assertTrue($client->addDnsRecord('example.com', ['type' => 'A', 'name' => '@', 'content' => '1.2.3.4']));
        $this->assertTrue($client->deleteDnsRecord('example.com', '1'));
        $this->assertSame([], $client->getWhoisContacts('example.com'));
        $this->assertTrue($client->updateWhoisContacts('example.com', []));
    }

    public function test_client_domain_management_page_actions(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['user_id' => $user->id]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'domain_name' => 'clientdomain-chunk8.com',
            'domain_registrar' => 'enom',
            'domain_expiration_date' => now()->addDays(15),
        ]);

        $domainService = Mockery::mock(DomainService::class);
        $domainService->shouldReceive('addDnsRecord')->once()->andReturn(true);
        $domainService->shouldReceive('getDnsRecords')->andReturn([
            ['id' => 'rec-1', 'type' => 'A', 'name' => '@', 'content' => '1.2.3.4', 'ttl' => 3600],
        ]);
        $domainService->shouldReceive('deleteDnsRecord')
            ->with(Mockery::type(Subscription::class), 'rec-1')
            ->once()
            ->andReturn(true);
        $domainService->shouldReceive('getWhoisContacts')->andReturn([
            'registrant' => [
                'name' => 'Jane Doe',
                'organization' => 'Acme',
                'email' => 'jane@example.com',
                'phone' => '+15551234567',
            ],
        ]);
        $domainService->shouldReceive('updateWhoisContacts')->once()->andReturn(true);
        $this->app->instance(DomainService::class, $domainService);

        $this->bootClientPanel($user);

        Livewire::test(DomainManagement::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$subscription])
            ->callTableAction('addDnsRecord', $subscription, [
                'type' => 'A',
                'name' => 'www',
                'content' => '5.6.7.8',
                'ttl' => 3600,
            ])
            ->assertHasNoTableActionErrors()
            ->callTableAction('deleteDnsRecord', $subscription, [
                'record_id' => 'rec-1',
            ])
            ->assertHasNoTableActionErrors()
            ->callTableAction('whois', $subscription, [
                'registrant' => [
                    'name' => 'Jane Updated',
                    'organization' => 'Acme Corp',
                    'email' => 'jane@acme.test',
                    'phone' => '+15559876543',
                ],
            ])
            ->assertHasNoTableActionErrors();
    }

    public function test_expiring_domains_widget_with_and_without_customer(): void
    {
        $userWithCustomer = User::factory()->create();
        $customer = Customer::factory()->create(['user_id' => $userWithCustomer->id]);
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'domain_name' => 'expiring-soon.com',
            'domain_registrar' => 'enom',
            'domain_expiration_date' => now()->addDays(15),
        ]);
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'domain_name' => 'not-expiring.com',
            'domain_registrar' => 'enom',
            'domain_expiration_date' => now()->addDays(90),
        ]);

        $this->bootClientPanel($userWithCustomer);

        Livewire::test(ExpiringDomains::class)
            ->assertSuccessful()
            ->assertSee('Domains expiring within 30 days')
            ->assertSee('1');

        $userWithoutCustomer = User::factory()->create();
        $this->bootClientPanel($userWithoutCustomer);

        Livewire::test(ExpiringDomains::class)
            ->assertSuccessful()
            ->assertSee('Domains expiring within 30 days')
            ->assertSee('0');
    }
}
