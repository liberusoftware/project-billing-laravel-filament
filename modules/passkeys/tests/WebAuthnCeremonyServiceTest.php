<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Liberu\Passkeys\Contracts\PasskeyCeremonyServiceContract;
use Liberu\Passkeys\Services\WebAuthnCeremonyService;
use Tests\TestCase;

uses(TestCase::class);

it('registers the passkey ceremony service contract in the container', function () {
    expect(app(PasskeyCeremonyServiceContract::class))->toBeInstanceOf(WebAuthnCeremonyService::class);
});

it('builds webauthn registration options for an authenticatable actor', function () {
    $actor = new GenericUser([
        'id' => 42,
        'email' => 'ada@example.test',
        'name' => 'Ada Lovelace',
    ]);

    $options = app(PasskeyCeremonyServiceContract::class)->beginRegistration($actor, 'example.test', 'Example App');

    expect($options['challenge'])->toBeString()->not->toBeEmpty()
        ->and($options['rp'])->toBe(['id' => 'example.test', 'name' => 'Example App'])
        ->and($options['user'])->toBe([
            'id' => rtrim(strtr(base64_encode('42'), '+/', '-_'), '='),
            'name' => 'ada@example.test',
            'displayName' => 'Ada Lovelace',
        ])
        ->and($options['pubKeyCredParams'])->toBe([
            ['type' => 'public-key', 'alg' => -7],
            ['type' => 'public-key', 'alg' => -257],
        ])
        ->and($options['authenticatorSelection'])->toBe([
            'residentKey' => 'required',
            'userVerification' => 'required',
        ])
        ->and($options['timeout'])->toBe(120000);
});

it('builds webauthn assertion options', function () {
    $options = app(PasskeyCeremonyServiceContract::class)->beginAssertion('example.test');

    expect($options['challenge'])->toBeString()->not->toBeEmpty()
        ->and($options['rpId'])->toBe('example.test')
        ->and($options['userVerification'])->toBe('required')
        ->and($options['timeout'])->toBe(120000);
});
