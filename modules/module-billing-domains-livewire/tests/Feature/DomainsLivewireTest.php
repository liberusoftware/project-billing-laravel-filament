<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Domains\Contracts\RegistrarClient;
use Liberu\Billing\Domains\Livewire\Components\DomainList;
use Liberu\Billing\Domains\Livewire\Components\DomainSearch;
use Liberu\Billing\Domains\Livewire\Components\DomainSupport;
use Liberu\Billing\Domains\Livewire\Components\DomainTldList;
use Liberu\Billing\Domains\Models\DnsRecord;
use Liberu\Billing\Domains\Models\Domain;
use Liberu\Billing\Domains\Models\DomainContact;
use Liberu\Billing\Domains\Models\DomainTld;
use Liberu\Billing\Domains\Models\EppOperation;
use Liberu\Billing\Domains\Services\RegistrarManager;
use Livewire\Livewire;

it('renders and manages domains, search, support, and TLDs through Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.domains.read', 'billing.domains.write']);

    $customerId = DB::table('customers')->insertGetId([
        'team_id' => $team->id,
        'name' => 'LW Domain Customer',
        'email' => 'lw-domain@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(RegistrarManager::class)->register('lw-reg', new class() implements RegistrarClient
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
            return ['.com'];
        }

        public function getDomainPrice(string $tld): float
        {
            return 14.99;
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

    // DomainList: create, update, register, renew, transfer, redeem
    $list = Livewire::test(DomainList::class)
        ->assertOk()
        ->set('name', 'livewire-example.com')
        ->set('registrar', 'lw-reg')
        ->call('createDomain')
        ->assertHasNoErrors();

    $domain = Domain::query()->where('name', 'livewire-example.com')->sole();

    $list->set('selectedDomainId', $domain->id)
        ->set('domainStatus', 'pending')
        ->set('domainRegistrar', 'lw-reg')
        ->call('updateDomain')
        ->assertHasNoErrors();

    expect($domain->fresh()->status)->toBe('pending');

    $list->set('customerId', (string) $customerId)
        ->call('register', $domain->id)
        ->assertHasNoErrors();

    expect($domain->fresh()->status)->toBe('registered');

    $list->set('renewalPeriod', 2)
        ->call('renew', $domain->id)
        ->assertHasNoErrors();

    $list->set('authCode', 'LW-AUTH-1')
        ->set('customerId', (string) $customerId)
        ->call('transfer', $domain->id)
        ->assertHasNoErrors();

    expect($domain->fresh()->status)->toBe('transfer_pending');

    $list->call('redeem', $domain->id)
        ->assertHasNoErrors();

    expect($domain->fresh()->status)->toBe('redemption_pending');

    // DomainSearch
    Livewire::test(DomainSearch::class)
        ->assertOk()
        ->set('domain', 'lw-search.com')
        ->set('registrar', 'lw-reg')
        ->call('search')
        ->assertHasNoErrors()
        ->assertSet('result.domain', 'lw-search.com')
        ->assertSet('result.available', true);

    // DomainSupport: createContact, saveDnsRecord, recordEppOperation
    Livewire::test(DomainSupport::class)
        ->assertOk()
        ->set('handle', 'LW-CON-1')
        ->set('contactName', 'Livewire Contact')
        ->set('email', 'lw-contact@example.com')
        ->call('createContact')
        ->assertHasNoErrors()
        ->set('domainId', (string) $domain->id)
        ->set('dnsType', 'A')
        ->set('dnsHost', '@')
        ->set('dnsValue', '192.0.2.55')
        ->set('dnsTtl', 3600)
        ->call('saveDnsRecord')
        ->assertHasNoErrors()
        ->set('domainId', (string) $domain->id)
        ->set('eppOperation', 'check')
        ->set('eppStatus', 'completed')
        ->set('eppCode', '1000')
        ->set('eppPayload', '{"ok":true}')
        ->call('recordEppOperation')
        ->assertHasNoErrors()
        ->set('domainId', (string) $domain->id)
        ->set('eppOperation', 'info')
        ->set('eppStatus', 'pending')
        ->set('eppCode', '')
        ->set('eppPayload', '')
        ->call('recordEppOperation')
        ->assertHasNoErrors();

    expect(DomainContact::query()->where('handle', 'LW-CON-1')->exists())->toBeTrue()
        ->and(DnsRecord::query()->where('domain_id', $domain->id)->exists())->toBeTrue()
        ->and(EppOperation::query()->where('domain_id', $domain->id)->where('operation', 'check')->exists())->toBeTrue()
        ->and(EppOperation::query()->where('domain_id', $domain->id)->where('operation', 'info')->exists())->toBeTrue();

    // DomainTldList
    Livewire::test(DomainTldList::class)
        ->assertOk()
        ->set('name', '.org')
        ->set('basePrice', '12.50')
        ->set('markupType', 'fixed')
        ->set('markupValue', '2.50')
        ->call('saveTld')
        ->assertHasNoErrors();

    expect(DomainTld::query()->where('name', '.org')->exists())->toBeTrue();
});

it('forbids Livewire domain components when the user has no current team', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.domains.read', 'billing.domains.write']);

    Livewire::test(DomainList::class)->assertForbidden();
    Livewire::test(DomainSupport::class)->assertForbidden();
});
