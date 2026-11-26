<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Domains\Contracts\RegistrarClient;
use Liberu\Billing\Domains\Filament\DomainsFilamentPlugin;
use Liberu\Billing\Domains\Filament\Resources\DnsRecordResource;
use Liberu\Billing\Domains\Filament\Resources\DomainContactResource;
use Liberu\Billing\Domains\Filament\Resources\DomainResource;
use Liberu\Billing\Domains\Filament\Resources\DomainResource\Pages\CreateDomain;
use Liberu\Billing\Domains\Filament\Resources\DomainResource\Pages\ListDomains;
use Liberu\Billing\Domains\Filament\Resources\DomainTldResource\Pages\CreateDomainTld;
use Liberu\Billing\Domains\Filament\Resources\DomainTldResource\Pages\ListDomainTlds;
use Liberu\Billing\Domains\Filament\Resources\EppOperationResource;
use Liberu\Billing\Domains\Models\Domain;
use Liberu\Billing\Domains\Models\DomainTld;
use Liberu\Billing\Domains\Models\EppOperation;
use Liberu\Billing\Domains\Services\RegistrarManager;
use Livewire\Livewire;

it('manages domains, TLDs, and supporting resources through Filament', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $team = $actor->ownedTeams()->sole();
    $actor->switchTeam($team);
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.domains.read', 'billing.domains.write']);

    Domain::resolveRelationUsing('team', fn ($model) => $model->belongsTo(Team::class, 'team_id'));
    EppOperation::resolveRelationUsing('team', fn ($model) => $model->belongsTo(Team::class, 'team_id'));

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);
    DomainsFilamentPlugin::make()->boot($panel);

    try {

        $customerId = DB::table('customers')->insertGetId([
            'team_id' => $team->id,
            'name' => 'Filament Domain Customer',
            'email' => 'filament-domain@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(RegistrarManager::class)->register('fil-reg', new class() implements RegistrarClient
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
                return 10.0;
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

        // Create domain via Filament page
        Livewire::test(CreateDomain::class)
            ->fillForm([
                'name' => 'filament-example.com',
                'status' => 'active',
                'registrar' => 'fil-reg',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $domain = Domain::query()->where('name', 'filament-example.com')->sole();

        // Table actions: register, renew, transfer, redeem
        Livewire::test(ListDomains::class)
            ->assertCanSeeTableRecords([$domain])
            ->callTableAction('register', $domain, data: ['customer_id' => (string) $customerId]);

        expect($domain->fresh()->status)->toBe('registered');

        Livewire::test(ListDomains::class)
            ->callTableAction('renew', $domain->fresh(), data: ['period' => 2]);

        expect($domain->fresh()->status)->toBe('registered');

        Livewire::test(ListDomains::class)
            ->callTableAction('transfer', $domain->fresh(), data: [
                'auth_code' => 'FIL-AUTH-1',
                'customer_id' => (string) $customerId,
                'registrar' => 'fil-reg',
            ]);

        expect($domain->fresh()->status)->toBe('transfer_pending');

        $domain->update(['status' => 'expired']);

        Livewire::test(ListDomains::class)
            ->callTableAction('redeem', $domain->fresh());

        expect($domain->fresh()->status)->toBe('redemption_pending');

        // Create and list DomainTld via Filament pages
        Livewire::test(CreateDomainTld::class)
            ->fillForm([
                'name' => '.com',
                'registrar_cost' => 8.5,
                'base_price' => 10.0,
                'markup_type' => 'percentage',
                'markup_value' => 10.0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tld = DomainTld::query()->where('name', '.com')->sole();

        Livewire::test(ListDomainTlds::class)
            ->assertCanSeeTableRecords([$tld]);

        // Supporting resources: DnsRecordResource, DomainContactResource, EppOperationResource
        $listComponent = new ListDomains();

        $dnsTable = DnsRecordResource::table(Table::make($listComponent));
        expect(array_keys($dnsTable->getColumns()))->toBe(['type', 'host', 'value', 'ttl']);

        $contactForm = DomainContactResource::form(Schema::make($listComponent));
        $contactTable = DomainContactResource::table(Table::make($listComponent));
        expect($contactForm->getComponents())->toHaveCount(3)
            ->and(array_keys($contactTable->getColumns()))->toBe(['handle', 'name', 'email']);

        $eppTable = EppOperationResource::table(Table::make($listComponent));
        expect(array_keys($eppTable->getColumns()))->toBe(['domain_id', 'operation', 'status', 'created_at'])
            ->and(EppOperationResource::getEloquentQuery()->count())->toBe(EppOperation::query()->where('team_id', $team->id)->count());
    } finally {
        Filament::setTenant(null);
    }
});

it('scopes Filament domain queries when no current team is active', function (): void {
    Domain::query()->create([
        'team_id' => 999,
        'name' => 'other-team.example.com',
        'status' => 'active',
    ]);

    $this->actingAs(User::factory()->create(), 'web');

    expect(DomainResource::getEloquentQuery()->count())->toBe(0);
});
