<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Liberu\Billing\Domains\Services\RegistrarManager;
use Liberu\Billing\ResellerClub\ResellerClubRegistrar;
use Liberu\Billing\ResellerClub\ResellerClubServiceProvider;

beforeEach(function (): void {
    config()->set('services.resellerclub', [
        'auth_userid' => 'rc-user',
        'api_key' => 'rc-key',
        'reseller_id' => 'rc-reseller',
        'base_url' => 'https://httpapi.resellerclub.test/api',
    ]);
});

it('registers the resellerclub registrar via the service provider', function (): void {
    (new ResellerClubServiceProvider(app()))->register();

    $manager = app()->make(RegistrarManager::class);

    expect($manager->client('resellerclub'))->toBeInstanceOf(ResellerClubRegistrar::class);
});

it('registers, renews, transfers, checks availability, and queries tlds and prices', function (): void {
    Http::fake([
        'https://httpapi.resellerclub.test/api/*' => Http::sequence()
            ->push(['status' => 'success', 'endtime' => '2027-11-22'])
            ->push(['status' => 'success', 'expiration-date' => '2028-11-22'])
            ->push(['status' => 'success', 'endtime' => '2029-11-22'])
            ->push(['com' => 'available'])
            ->push(['com' => 'regthroughothers'])
            ->push(['tlds' => ['com', ['tld' => '.org']]])
            ->push(['tlds' => 'not-an-array'])
            ->push(['sellingprice' => '14.50']),
    ]);

    $registrar = app(ResellerClubRegistrar::class);

    $registered = $registrar->registerDomain('example.com', 77);
    expect($registered['expiration_date']->toDateString())->toBe('2027-11-22');

    $renewed = $registrar->renewDomain('example.com', 1);
    expect($renewed['new_expiration_date']->toDateString())->toBe('2028-11-22');

    $transferred = $registrar->transferDomain('example.com', 'EPP-999', 77);
    expect($transferred['expiration_date']->toDateString())->toBe('2029-11-22');

    expect($registrar->checkAvailability('free.com'))->toBeTrue();
    expect($registrar->checkAvailability('taken.com'))->toBeFalse();
    expect($registrar->getAvailableTlds())->toBe(['.com', '.org']);
    expect($registrar->getAvailableTlds())->toBe([]);
    expect($registrar->getDomainPrice('.com'))->toBe(14.5);
});

it('manages dns records and whois contacts and rejects failed operations', function (): void {
    Http::fake([
        'https://httpapi.resellerclub.test/api/*' => Http::sequence()
            ->push([
                'dns-records' => [
                    ['id' => '55', 'type' => 'A', 'host' => '@', 'value' => '192.0.2.5', 'ttl' => 1800],
                ],
            ])
            ->push(['dns-records' => 'none'])
            ->push(['status' => 'success'])
            ->push(['status' => 'success'])
            ->push(['contacts' => ['registrant' => ['email' => 'owner@example.com']]])
            ->push(['contacts' => null])
            ->push(['status' => 'success'])
            ->push(['status' => 'error', 'message' => 'Insufficient balance']),
    ]);

    $registrar = app(ResellerClubRegistrar::class);

    expect($registrar->getDnsRecords('example.com'))->toBe([
        ['id' => '55', 'type' => 'A', 'name' => '@', 'content' => '192.0.2.5', 'ttl' => 1800],
    ]);
    expect($registrar->getDnsRecords('example.com'))->toBe([]);

    expect($registrar->addDnsRecord('example.com', [
        'type' => 'TXT',
        'name' => '@',
        'content' => 'v=spf1 ~all',
        'ttl' => 600,
    ]))->toBeTrue();

    expect($registrar->deleteDnsRecord('example.com', '55'))->toBeTrue();

    expect($registrar->getWhoisContacts('example.com'))->toBe([
        'registrant' => ['email' => 'owner@example.com'],
    ]);
    expect($registrar->getWhoisContacts('example.com'))->toBe([]);

    expect($registrar->updateWhoisContacts('example.com', ['registrant' => ['email' => 'new@example.com']]))->toBeTrue();

    expect(fn () => $registrar->registerDomain('fail.com', 77))
        ->toThrow(RuntimeException::class, 'Insufficient balance');
});
