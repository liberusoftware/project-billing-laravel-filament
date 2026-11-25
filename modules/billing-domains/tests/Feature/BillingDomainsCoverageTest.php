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

class BillingDomainsCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function bootClientPanel(User $user): void
    {
        $this->actingAs($user);
        $panel = Filament::getPanel('client');
        Filament::setCurrentPanel($panel);
        $panel->boot();
    }

    public function test_process_domain_renewals_handles_failure_and_marks_failed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $customer = Customer::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        $subscription = Subscription::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'domain_name' => 'failrenew.com',
            'domain_registrar' => 'enom',
        ]);
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'status' => 'paid',
        ]);
        $renewal = DomainRenewal::create([
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription->id,
            'domain' => 'failrenew.com',
            'registrar' => 'enom',
            'status' => 'pending',
        ]);

        $domainService = Mockery::mock(DomainService::class);
        $domainService->shouldReceive('renewDomain')->once()->andThrow(new RuntimeException('Registrar timeout'));
        $this->app->instance(DomainService::class, $domainService);

        $this->artisan('domains:process-renewals')->assertExitCode(0);

        $renewal->refresh();
        $this->assertSame('failed', $renewal->status);
        $this->assertSame('Registrar timeout', $renewal->last_error);
    }

    public function test_sync_domains_handles_exception_per_domain(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        Subscription::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'domain_name' => 'broken-sync.com',
            'domain_registrar' => 'enom',
        ]);

        $enom = Mockery::mock(EnomClient::class);
        $enom->shouldReceive('getDomainExpiration')
            ->with('broken-sync.com')
            ->once()
            ->andThrow(new RuntimeException('API unreachable'));
        $this->app->instance(EnomClient::class, $enom);

        $this->artisan('domains:sync')
            ->expectsOutputToContain('Failed to sync broken-sync.com')
            ->assertExitCode(0);
    }

    public function test_sync_enom_domains_command_success_and_error(): void
    {
        $pricing = Mockery::mock(DomainPricingService::class);
        $pricing->shouldReceive('syncTldsFromEnom')->once();
        $this->app->instance(DomainPricingService::class, $pricing);

        $this->artisan('enom:sync-domains')
            ->expectsOutputToContain('completed successfully')
            ->assertExitCode(0);

        $failingPricing = Mockery::mock(DomainPricingService::class);
        $failingPricing->shouldReceive('syncTldsFromEnom')->once()->andThrow(new Exception('Enom down'));
        $this->app->instance(DomainPricingService::class, $failingPricing);

        $this->artisan('enom:sync-domains')
            ->expectsOutputToContain('Enom down')
            ->assertExitCode(0);
    }

    public function test_renew_domain_on_payment_ignores_non_paid_status(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
        $invoice = Invoice::factory()->create([
            'team_id' => $user->currentTeam->id,
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

        $this->assertDatabaseHas('tlds', ['name' => '.com']);
        $this->assertDatabaseHas('tlds', ['name' => '.net']);
    }

    public function test_enom_client_remaining_methods_and_error_guards(): void
    {
        config([
            'services.enom.api_url' => 'https://enom.example.test/interface.asp',
            'services.enom.username' => 'user',
            'services.enom.password' => 'pass',
        ]);

        Http::fake([
            'https://enom.example.test/interface.asp*command=GetTLDList*' => Http::response(
                '<interface-response><ErrCount>0</ErrCount><tldlist><tld><tld>.com</tld></tld><tld><tld>.org</tld></tld></tldlist></interface-response>',
                200
            ),
            'https://enom.example.test/interface.asp*command=PE_GetDomainPrice*' => Http::response(
                '<interface-response><ErrCount>0</ErrCount><price>11.25</price></interface-response>',
                200
            ),
            'https://enom.example.test/interface.asp*command=SetHosts*' => Http::response(
                '<interface-response><ErrCount>0</ErrCount></interface-response>',
                200
            ),
            'https://enom.example.test/interface.asp*command=GetWhoisContact*' => Http::response(
                '<interface-response><ErrCount>0</ErrCount><contacts><contact><ContactType>Registrant</ContactType><FirstName>Jane</FirstName><LastName>Doe</LastName><EmailAddress>jane@example.com</EmailAddress></contact></contacts></interface-response>',
                200
            ),
        ]);

        $client = new EnomClient;
        $this->assertSame(['.com', '.org'], $client->getAvailableTlds());
        $this->assertSame(11.25, $client->getDomainPrice('.com'));
        $this->assertTrue($client->deleteDnsRecord('example.com', '42'));
        $contacts = $client->getWhoisContacts('example.com');
        $this->assertSame('Jane', $contacts['Registrant']['first_name']);

        // HTTP failure (line 228)
        Http::fake([
            'https://enom.example.test/interface.asp*' => Http::response('Server error', 500),
        ]);
        try {
            $client->getAvailableTlds();
            $this->fail('Expected HTTP failure exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('eNom API request failed', $e->getMessage());
        }

        // DOCTYPE / empty body guard (line 232)
        Http::fake([
            'https://enom.example.test/interface.asp*' => Http::response('<!DOCTYPE foo><interface-response/>', 200),
        ]);
        try {
            $client->getAvailableTlds();
            $this->fail('Expected invalid response exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid eNom API response', $e->getMessage());
        }

        // Invalid XML / wrong root tag guard (line 238)
        Http::fake([
            'https://enom.example.test/interface.asp*' => Http::response('<unexpected-root/>', 200),
        ]);
        try {
            $client->getAvailableTlds();
            $this->fail('Expected invalid XML root exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid eNom API response', $e->getMessage());
        }
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

    public function test_client_domain_management_page_and_expiring_domains_widget(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $customer = Customer::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        $subscription = Subscription::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'domain_name' => 'clientdomain.com',
            'domain_registrar' => 'enom',
            'domain_expiration_date' => now()->addDays(15),
        ]);

        $domainService = Mockery::mock(DomainService::class);
        $domainService->shouldReceive('addDnsRecord')->once()->andReturn(true);
        $domainService->shouldReceive('getDnsRecords')->andReturn([
            ['id' => 'rec-1', 'type' => 'A', 'name' => '@', 'content' => '1.2.3.4', 'ttl' => 3600],
        ]);
        $domainService->shouldReceive('deleteDnsRecord')->with(Mockery::type(Subscription::class), 'rec-1')->once()->andReturn(true);
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

        Livewire::test(ExpiringDomains::class)
            ->assertSuccessful()
            ->assertSee('Domains expiring within 30 days');
    }
}
