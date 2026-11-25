<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Liberu\Billing\Domains\Services\RegistrarManager;
use Liberu\Billing\Enom\EnomRegistrar;
use Liberu\Billing\Enom\EnomServiceProvider;

beforeEach(function (): void {
    config()->set('services.enom', [
        'username' => 'enom-user',
        'password' => 'enom-pass',
        'base_url' => 'https://reseller.enom.test/interface.asp',
    ]);
});

it('registers the enom registrar via the service provider', function (): void {
    (new EnomServiceProvider(app()))->register();

    $manager = app()->make(RegistrarManager::class);

    expect($manager->client('enom'))->toBeInstanceOf(EnomRegistrar::class);
});

it('registers, renews, transfers, checks availability, and queries tlds and prices', function (): void {
    Http::fake([
        'https://reseller.enom.test/interface.asp*' => Http::sequence()
            ->push(['RRPCode' => '200', 'ErrCount' => '0', 'ExpirationDate' => '2027-11-22'])
            ->push(['RRPCode' => '200', 'ErrCount' => '0', 'NewExpirationDate' => '2028-11-22'])
            ->push(['RRPCode' => '200', 'ErrCount' => '0', 'ExpirationDate' => '2029-11-22'])
            ->push(['RRPCode' => '210'])
            ->push(['RRPCode' => '211'])
            ->push(['TLD' => ['com', '.net', 'org']])
            ->push(['RetailPrice' => '12.95']),
    ]);

    $registrar = app(EnomRegistrar::class);

    $registered = $registrar->registerDomain('example.com', 42);
    expect($registered['expiration_date']->toDateString())->toBe('2027-11-22');

    $renewed = $registrar->renewDomain('example.com', 2);
    expect($renewed['new_expiration_date']->toDateString())->toBe('2028-11-22');

    $transferred = $registrar->transferDomain('example.com', 'AUTH-123', 42);
    expect($transferred['expiration_date']->toDateString())->toBe('2029-11-22');

    expect($registrar->checkAvailability('available.com'))->toBeTrue();
    expect($registrar->checkAvailability('taken.com'))->toBeFalse();
    expect($registrar->getAvailableTlds())->toBe(['.com', '.net', '.org']);
    expect($registrar->getDomainPrice('.com'))->toBe(12.95);
});

it('manages dns records and whois contacts and rejects failed operations', function (): void {
    Http::fake([
        'https://reseller.enom.test/interface.asp*' => Http::sequence()
            ->push([
                'Record' => [
                    ['HostID' => '101', 'RecordType' => 'A', 'HostName' => '@', 'Address' => '192.0.2.1', 'ttl' => 600],
                ],
            ])
            ->push(['record' => 'invalid-non-array'])
            ->push(['RRPCode' => '200', 'ErrCount' => '0'])
            ->push(['RRPCode' => '200', 'ErrCount' => '0'])
            ->push(['RegistrantFirstName' => 'Jane', 'RegistrantLastName' => 'Doe'])
            ->push(['RRPCode' => '200', 'ErrCount' => '0'])
            ->push(['RRPCode' => '540', 'ErrCount' => '1', 'Err1' => 'Domain unavailable']),
    ]);

    $registrar = app(EnomRegistrar::class);

    expect($registrar->getDnsRecords('example.com'))->toBe([
        ['id' => '101', 'type' => 'A', 'name' => '@', 'content' => '192.0.2.1', 'ttl' => 600],
    ]);
    expect($registrar->getDnsRecords('example.com'))->toBe([]);

    expect($registrar->addDnsRecord('example.com', [
        'name' => 'www',
        'type' => 'CNAME',
        'content' => 'example.com',
        'ttl' => 300,
    ]))->toBeTrue();

    expect($registrar->deleteDnsRecord('example.com', '101'))->toBeTrue();

    expect($registrar->getWhoisContacts('example.com'))->toBe([
        'RegistrantFirstName' => 'Jane',
        'RegistrantLastName' => 'Doe',
    ]);

    expect($registrar->updateWhoisContacts('example.com', ['registrant' => ['email' => 'jane@example.com']]))->toBeTrue();

    expect(fn () => $registrar->registerDomain('taken.com', 42))
        ->toThrow(RuntimeException::class, 'Enom rejected the operation.');
});
