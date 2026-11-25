<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Communications\Actions\CreateCallRateRule;
use Liberu\Billing\Communications\Actions\CreateCommunicationProvider;
use Liberu\Billing\Communications\Actions\CreateCommunicationService;
use Liberu\Billing\Communications\Actions\CreateVoipAccount;
use Liberu\Billing\Communications\Actions\ImportCommunicationUsage;
use Liberu\Billing\Communications\Actions\IngestCallDetailRecord;
use Liberu\Billing\Communications\Actions\ProvisionCommunicationNumber;
use Liberu\Billing\Communications\Actions\ProvisionVoipAccount;
use Liberu\Billing\Communications\Actions\TransitionCommunicationService;
use Liberu\Billing\Communications\Contracts\VoiceProvider;
use Liberu\Billing\Communications\Events\CommunicationNumberProvisioned;
use Liberu\Billing\Communications\Events\CommunicationProviderCreated;
use Liberu\Billing\Communications\Events\CommunicationServiceStatusChanged;
use Liberu\Billing\Communications\Events\CommunicationUsageImported;
use Liberu\Billing\Communications\Models\CommunicationNumber;
use Liberu\Billing\Communications\Models\CommunicationProvider;
use Liberu\Billing\Communications\Models\CommunicationService;
use Liberu\Billing\Communications\Models\VoipFraudAlert;
use Liberu\Billing\Communications\Queries\ListCommunicationRecords;
use Liberu\Billing\Communications\Queries\ListCommunicationsRecords;
use Liberu\Billing\Communications\Services\VoiceProviderRegistry;

it('manages communication providers, services, numbers, usage imports, and queries', function (): void {
    Event::fake([
        CommunicationProviderCreated::class,
        CommunicationUsageImported::class,
        CommunicationNumberProvisioned::class,
        CommunicationServiceStatusChanged::class,
    ]);

    expect(fn () => (new CreateCommunicationProvider())->handle(0, []))
        ->toThrow(InvalidArgumentException::class);

    $provider = (new CreateCommunicationProvider())->handle(10, [
        'name' => 'Twilio Carrier',
        'driver' => 'twilio',
        'configuration' => ['region' => 'us1'],
    ]);
    expect($provider->configuration)->toBe(['region' => 'us1']);
    Event::assertDispatched(CommunicationProviderCreated::class, 1);

    expect(fn () => (new ImportCommunicationUsage())->handle(0, []))
        ->toThrow(InvalidArgumentException::class);

    $import = (new ImportCommunicationUsage())->handle(10, [
        'provider' => 'twilio',
        'rows' => 42,
        'total_amount_minor' => 1500,
        'currency' => 'usd',
    ]);
    expect($import->rows)->toBe(42)
        ->and($import->total_amount_minor)->toBe(1500);
    Event::assertDispatched(CommunicationUsageImported::class, 1);

    $service = (new CreateCommunicationService())->handle(10, ['name' => 'SIP Trunk']);
    expect(fn () => (new TransitionCommunicationService())->handle($service, 'unknown'))
        ->toThrow(InvalidArgumentException::class);

    $suspended = (new TransitionCommunicationService())->handle($service, 'suspended');
    expect($suspended->status)->toBe('suspended');
    Event::assertDispatched(CommunicationServiceStatusChanged::class, 1);

    expect(fn () => (new ProvisionCommunicationNumber())->handle(0, []))
        ->toThrow(InvalidArgumentException::class);

    $number = (new ProvisionCommunicationNumber())->handle(10, [
        'service_id' => $service->id,
        'number' => '+15551234567',
        'type' => 'did',
    ]);
    expect($number->number)->toBe('+15551234567');
    Event::assertDispatched(CommunicationNumberProvisioned::class, 1);

    expect((new ListCommunicationsRecords())->handle(10)->pluck('id')->all())->toBe([$service->id])
        ->and((new ListCommunicationRecords())->handle(CommunicationProvider::class, 10)->pluck('id')->all())->toBe([$provider->id]);
});

it('validates voice actions, raises fraud alerts on CDR ingestion, and enforces provider registry uniqueness', function (): void {
    $teamId = 10;
    $customerId = 1;
    if (Illuminate\Support\Facades\Schema::hasTable('teams')) {
        $ownerId = Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'name' => 'Comm Owner',
            'email' => 'comm-domain-owner@example.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Illuminate\Support\Facades\DB::table('teams')->insert([
            'id' => $teamId,
            'user_id' => $ownerId,
            'name' => 'Comm Team',
            'personal_team' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    if (Illuminate\Support\Facades\Schema::hasTable('customers')) {
        $customerId = (int) Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'name' => 'Comm Customer',
            'email' => 'comm-domain-customer@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    expect(fn () => (new CreateCallRateRule())->handle(0, []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new CreateVoipAccount())->handle($teamId, ['platform' => 'sip']))
        ->toThrow(InvalidArgumentException::class);

    $account = (new CreateVoipAccount())->handle($teamId, [
        'customer_id' => $customerId,
        'platform' => 'sip',
        'sip_username' => 'sipuser',
        'sip_secret' => 'secret123',
        'credit_limit' => 10.0,
    ]);

    expect(fn () => (new IngestCallDetailRecord())->handle($account, []))
        ->toThrow(InvalidArgumentException::class);

    // Zero duration call (hits rate() zero-duration early return)
    $zeroCdr = (new IngestCallDetailRecord())->handle($account, [
        'external_id' => 'cdr-zero',
        'destination' => '+15550000',
        'started_at' => now()->toIso8601String(),
        'duration_seconds' => 0,
    ]);
    expect((float) $zeroCdr->rated_cost)->toBe(0.0);

    // Fraud-triggering call: high cost, long duration, high-risk prefix, credit limit
    (new CreateCallRateRule())->handle(10, [
        'name' => 'Premium',
        'destination_prefix' => '+900',
        'connection_fee' => 5.0,
        'rate_per_minute' => 1.0,
        'billing_increment_seconds' => 60,
        'currency' => 'USD',
    ]);

    config()->set('voip.fraud.single_call_cost', 50);
    config()->set('voip.fraud.call_duration_seconds', 3600);
    config()->set('voip.fraud.high_risk_prefixes', ['+900']);

    $fraudCdr = (new IngestCallDetailRecord())->handle($account, [
        'external_id' => 'cdr-fraud',
        'destination' => '+900123456',
        'started_at' => now()->toIso8601String(),
        'duration_seconds' => 4000,
    ]);

    $alerts = VoipFraudAlert::query()->forTeam(10)->where('call_detail_record_id', $fraudCdr->id)->get();
    expect($alerts->pluck('rule')->all())->toEqualCanonicalizing([
        'high_call_cost',
        'long_duration',
        'high_risk_destination',
        'credit_limit',
    ])->and($alerts->first()->context)->toBeArray();

    // Provisioning a terminated VoIP account fails
    $account->update(['status' => 'terminated']);
    expect(fn () => app(ProvisionVoipAccount::class)->handle($account))
        ->toThrow(InvalidArgumentException::class, 'A terminated VoIP account cannot be provisioned.');

    // VoiceProviderRegistry rejects blank or duplicate keys
    $registry = new VoiceProviderRegistry();
    $blank = new class() implements VoiceProvider
    {
        public function key(): string
        {
            return '  ';
        }

        public function provision(array $account): array
        {
            return [];
        }
    };
    expect(fn () => $registry->register($blank))->toThrow(InvalidArgumentException::class);

    $valid = new class() implements VoiceProvider
    {
        public function key(): string
        {
            return 'carrier-x';
        }

        public function provision(array $account): array
        {
            return ['ok' => true];
        }
    };
    $registry->register($valid);
    expect(fn () => $registry->register($valid))->toThrow(InvalidArgumentException::class);
});

it('enforces communication service and record policies', function (): void {
    $actor = new User();
    $actor->forceFill(['current_team_id' => 10]);

    $ownService = new CommunicationService(['team_id' => 10]);
    $foreignService = new CommunicationService(['team_id' => 20]);
    $ownNumber = new CommunicationNumber(['team_id' => 10]);

    Sanctum::actingAs($actor, ['billing.communications.read']);
    expect(Gate::allows('viewAny', CommunicationService::class))->toBeTrue()
        ->and(Gate::allows('view', $ownService))->toBeTrue()
        ->and(Gate::denies('view', $foreignService))->toBeTrue()
        ->and(Gate::denies('create', CommunicationService::class))->toBeTrue()
        ->and(Gate::allows('viewAny', CommunicationNumber::class))->toBeTrue()
        ->and(Gate::allows('view', $ownNumber))->toBeTrue();

    Sanctum::actingAs($actor, ['billing.communications.write']);
    expect(Gate::allows('create', CommunicationService::class))->toBeTrue()
        ->and(Gate::allows('update', $ownService))->toBeTrue()
        ->and(Gate::denies('update', $foreignService))->toBeTrue()
        ->and(Gate::allows('create', CommunicationNumber::class))->toBeTrue()
        ->and(Gate::allows('update', $ownNumber))->toBeTrue();
});
