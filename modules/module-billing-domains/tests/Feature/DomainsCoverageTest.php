<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Domains\Actions\CreateDomain;
use Liberu\Billing\Domains\Actions\CreateDomainContact;
use Liberu\Billing\Domains\Actions\DeleteDomain;
use Liberu\Billing\Domains\Actions\RecordEppOperation;
use Liberu\Billing\Domains\Actions\RedeemDomain;
use Liberu\Billing\Domains\Actions\RegisterDomain;
use Liberu\Billing\Domains\Actions\RenewDomain;
use Liberu\Billing\Domains\Actions\TransferDomain;
use Liberu\Billing\Domains\Actions\UpsertDomainTld;
use Liberu\Billing\Domains\Contracts\RegistrarClient;
use Liberu\Billing\Domains\Events\DomainContactCreated;
use Liberu\Billing\Domains\Events\DomainDeleted;
use Liberu\Billing\Domains\Events\DomainRedeemed;
use Liberu\Billing\Domains\Events\DomainRegistered;
use Liberu\Billing\Domains\Events\DomainRenewed;
use Liberu\Billing\Domains\Events\DomainTldUpserted;
use Liberu\Billing\Domains\Events\DomainTransferred;
use Liberu\Billing\Domains\Models\Domain;
use Liberu\Billing\Domains\Models\DomainContact;
use Liberu\Billing\Domains\Models\DomainTld;
use Liberu\Billing\Domains\Models\EppOperation;
use Liberu\Billing\Domains\Policies\DomainPolicy;
use Liberu\Billing\Domains\Policies\DomainSupportPolicy;
use Liberu\Billing\Domains\Queries\ListDomainsRecords;
use Liberu\Billing\Domains\Queries\SearchDomains;
use Liberu\Billing\Domains\Services\DomainPricingService;
use Liberu\Billing\Domains\Services\RegistrarManager;
use Liberu\Billing\Domains\Support\AbstractRegistrarClient;
use Liberu\Billing\Domains\Support\CustomerReference;

function makeFakeRegistrarClient(
    bool $acceptRegister = true,
    bool $acceptRenew = true,
    bool $acceptTransfer = true,
    bool $available = true,
): RegistrarClient {
    return new class($acceptRegister, $acceptRenew, $acceptTransfer, $available) implements RegistrarClient
    {
        public function __construct(
            private bool $acceptRegister,
            private bool $acceptRenew,
            private bool $acceptTransfer,
            private bool $available,
        ) {}

        public function registerDomain(string $domainName, mixed $customerId): ?array
        {
            return $this->acceptRegister
                ? ['expiration_date' => now()->addYear(), 'epp_code' => '1000']
                : null;
        }

        public function renewDomain(string $domainName, int $period): ?array
        {
            return $this->acceptRenew
                ? ['new_expiration_date' => now()->addYears($period), 'epp_code' => '1000']
                : null;
        }

        public function transferDomain(string $domainName, string $authCode, mixed $customerId): ?array
        {
            return $this->acceptTransfer
                ? ['expiration_date' => now()->addYear(), 'epp_code' => '1001']
                : null;
        }

        public function checkAvailability(string $domainName): bool
        {
            return $this->available;
        }

        public function getAvailableTlds(): array
        {
            return ['', '.', 'com', '.net'];
        }

        public function getDomainPrice(string $tld): float
        {
            return $tld === '.net' ? 12.50 : 9.99;
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
    };
}

it('creates domain contacts, records EPP operations, redeems, lists, and deletes domains', function (): void {
    Event::fake([
        DomainContactCreated::class,
        DomainRedeemed::class,
        DomainDeleted::class,
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();

    expect(fn () => app(CreateDomainContact::class)->execute(0, []))
        ->toThrow(InvalidArgumentException::class);

    $contact = app(CreateDomainContact::class)->execute($team->id, [
        'handle' => ' hdl-01 ',
        'name' => ' Jane Doe ',
        'email' => 'JANE@Example.COM',
        'details' => ['phone' => '+15550001'],
    ]);

    expect($contact)->toBeInstanceOf(DomainContact::class)
        ->and($contact->handle)->toBe('HDL-01')
        ->and($contact->name)->toBe('Jane Doe')
        ->and($contact->email)->toBe('jane@example.com')
        ->and($contact->details)->toBe(['phone' => '+15550001']);

    Event::assertDispatched(DomainContactCreated::class);

    $domain = app(CreateDomain::class)->handle($team->id, [
        'name' => 'lifecycle.example.com',
        'registrar' => 'test-reg',
    ]);

    $listed = app(ListDomainsRecords::class)->handle($team->id);
    expect($listed)->toHaveCount(1)
        ->and($listed->first()->getKey())->toBe($domain->getKey());

    $epp = app(RecordEppOperation::class)->execute($domain, 'info', 'completed', ['note' => 'checked'], '1000');
    expect($epp)->toBeInstanceOf(EppOperation::class)
        ->and($epp->payload)->toBe(['note' => 'checked']);

    $redeemed = app(RedeemDomain::class)->execute($domain);
    expect($redeemed->status)->toBe('redemption_pending')
        ->and($redeemed->metadata)->toHaveKey('redemption_requested_at');
    Event::assertDispatched(DomainRedeemed::class);

    app(DeleteDomain::class)->execute($redeemed);
    expect(Domain::query()->find($domain->getKey()))->toBeNull();
    Event::assertDispatched(DomainDeleted::class);
});

it('registers, renews, and transfers domains and handles registrar failures', function (): void {
    Event::fake([
        DomainRegistered::class,
        DomainRenewed::class,
        DomainTransferred::class,
    ]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();

    $customerId = DB::table('customers')->insertGetId([
        'team_id' => $team->id,
        'name' => 'Domain Customer',
        'email' => 'domain-customer@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $registrars = app(RegistrarManager::class);
    $registrars->register('ok-reg', makeFakeRegistrarClient());
    $registrars->register('fail-reg', makeFakeRegistrarClient(false, false, false, false));

    $domain = app(CreateDomain::class)->handle($team->id, [
        'name' => 'ops.example.com',
        'registrar' => 'ok-reg',
    ]);

    $registered = app(RegisterDomain::class)->execute($domain, $customerId);
    expect($registered->status)->toBe('registered')
        ->and($registered->registered_at)->not->toBeNull();
    Event::assertDispatched(DomainRegistered::class);

    expect(fn () => app(RenewDomain::class)->execute($registered, 0))
        ->toThrow(InvalidArgumentException::class);

    $renewed = app(RenewDomain::class)->execute($registered, 2);
    expect($renewed->status)->toBe('registered')
        ->and($renewed->expires_at)->not->toBeNull();
    Event::assertDispatched(DomainRenewed::class);

    expect(fn () => app(TransferDomain::class)->execute($renewed, '   ', $customerId))
        ->toThrow(InvalidArgumentException::class);

    $transferred = app(TransferDomain::class)->execute($renewed, 'AUTH-XYZ', $customerId, 'ok-reg');
    expect($transferred->status)->toBe('transfer_pending')
        ->and($transferred->transfer_status)->toBe('pending');
    Event::assertDispatched(DomainTransferred::class);

    $failDomain = app(CreateDomain::class)->handle($team->id, [
        'name' => 'fail.example.com',
        'registrar' => 'fail-reg',
    ]);

    expect(fn () => app(RegisterDomain::class)->execute($failDomain, $customerId))
        ->toThrow(RuntimeException::class)
        ->and(fn () => app(RenewDomain::class)->execute($failDomain, 1))
        ->toThrow(RuntimeException::class)
        ->and(fn () => app(TransferDomain::class)->execute($failDomain, 'AUTH-FAIL', $customerId))
        ->toThrow(RuntimeException::class);
});

it('upserts domain TLDs, calculates pricing, syncs TLDs, and searches domains', function (): void {
    Event::fake([DomainTldUpserted::class]);

    expect(fn () => app(UpsertDomainTld::class)->execute(['name' => 'x', 'base_price' => -1]))
        ->toThrow(InvalidArgumentException::class);

    $tld = app(UpsertDomainTld::class)->execute([
        'name' => 'io',
        'registrar_cost' => 20.0,
        'base_price' => 25.0,
        'markup_type' => 'fixed',
        'markup_value' => 5.0,
        'enabled' => true,
    ]);

    expect($tld)->toBeInstanceOf(DomainTld::class)
        ->and($tld->name)->toBe('.io')
        ->and($tld->calculatePrice())->toBe(30.0);
    Event::assertDispatched(DomainTldUpserted::class);

    $registrars = app(RegistrarManager::class);
    $registrars->register('search-reg', makeFakeRegistrarClient());

    $pricing = app(DomainPricingService::class);
    expect(fn () => $pricing->syncTlds('search-reg', -5))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $pricing->calculateDomainPrice('localhost'))
        ->toThrow(InvalidArgumentException::class);

    expect($pricing->syncTlds('search-reg', 10))->toBe(2);

    $search = app(SearchDomains::class);
    expect(fn () => $search->execute('not a valid domain!', 'search-reg'))
        ->toThrow(InvalidArgumentException::class);

    $result = $search->execute('example.com.', 'search-reg', ['com', 'net', 'org']);
    expect($result['domain'])->toBe('example.com')
        ->and($result['available'])->toBeTrue()
        ->and($result['price'])->toBe(9.99)
        ->and($result['suggestions'])->toHaveCount(2);
});

it('enforces DomainPolicy and DomainSupportPolicy rules', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);

    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->ownedTeams()->sole();
    $otherUser->switchTeam($otherTeam);

    $domain = app(CreateDomain::class)->handle($team->id, ['name' => 'policy.example.com']);
    $contact = app(CreateDomainContact::class)->execute($team->id, [
        'handle' => 'POL-1',
        'name' => 'Policy Contact',
        'email' => 'policy@example.com',
    ]);

    $domainPolicy = new DomainPolicy();
    $supportPolicy = new DomainSupportPolicy();

    expect($domainPolicy->viewAny(null))->toBeFalse()
        ->and($domainPolicy->create(null))->toBeFalse()
        ->and($domainPolicy->view(null, $domain))->toBeFalse()
        ->and($supportPolicy->viewAny(null))->toBeFalse()
        ->and($supportPolicy->create(null))->toBeFalse()
        ->and($supportPolicy->view(null, $contact))->toBeFalse();

    Sanctum::actingAs($user, ['billing.domains.read', 'billing.domains.write']);
    expect($domainPolicy->viewAny($user))->toBeTrue()
        ->and($domainPolicy->create($user))->toBeTrue()
        ->and($domainPolicy->view($user, $domain))->toBeTrue()
        ->and($domainPolicy->update($user, $domain))->toBeTrue()
        ->and($domainPolicy->delete($user, $domain))->toBeTrue()
        ->and($supportPolicy->viewAny($user))->toBeTrue()
        ->and($supportPolicy->create($user))->toBeTrue()
        ->and($supportPolicy->view($user, $contact))->toBeTrue()
        ->and($supportPolicy->update($user, $contact))->toBeTrue();

    Sanctum::actingAs($otherUser, ['billing.domains.read', 'billing.domains.write']);
    expect($domainPolicy->view($otherUser, $domain))->toBeFalse()
        ->and($domainPolicy->update($otherUser, $domain))->toBeFalse()
        ->and($domainPolicy->delete($otherUser, $domain))->toBeFalse()
        ->and($supportPolicy->view($otherUser, $contact))->toBeFalse()
        ->and($supportPolicy->update($otherUser, $contact))->toBeFalse();
});

it('validates RegistrarManager, AbstractRegistrarClient, and CustomerReference edge cases', function (): void {
    $manager = new RegistrarManager();
    expect(fn () => $manager->register('   ', makeFakeRegistrarClient()))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $manager->client('unknown'))
        ->toThrow(InvalidArgumentException::class);

    Http::fake([
        'https://registrar.test/post' => Http::response('status=ok&code=1000', 200),
    ]);

    $client = new class(app(HttpFactory::class)) extends AbstractRegistrarClient
    {
        public function callDomainParts(string $domain): array
        {
            return $this->domainParts($domain);
        }

        public function callPost(string $url, array $payload): array
        {
            return $this->post($url, $payload);
        }

        public function callConfigValue(string $key, ?string $default = null): string
        {
            return $this->configValue($key, $default);
        }

        public function callDateValue(mixed $value): ?Carbon
        {
            return $this->dateValue($value);
        }
    };

    expect(fn () => $client->callDomainParts('invalid'))
        ->toThrow(InvalidArgumentException::class)
        ->and($client->callPost('https://registrar.test/post', ['domain' => 'example.com']))
        ->toBe(['status' => 'ok', 'code' => '1000'])
        ->and(fn () => $client->callConfigValue('billing.domains.missing_secret'))
        ->toThrow(InvalidArgumentException::class)
        ->and($client->callDateValue(null))->toBeNull()
        ->and($client->callDateValue(''))->toBeNull()
        ->and($client->callDateValue('2027-01-01'))->toBeInstanceOf(Carbon::class)
        ->and($client->callDateValue('not-a-valid-date-xyz'))->toBeNull();

    $db = app('db');
    expect(CustomerReference::assertBelongsToTeam($db, null, 1))->toBeNull();

    Schema::shouldReceive('hasTable')->once()->with('customers')->andReturn(false);
    expect(fn () => CustomerReference::assertBelongsToTeam($db, 1, 1))
        ->toThrow(InvalidArgumentException::class);
});
