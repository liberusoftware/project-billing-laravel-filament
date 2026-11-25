<?php

declare(strict_types=1);

namespace Liberu\Passkeys\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Passkeys\Contracts\PasskeyCeremonyServiceContract;

final class WebAuthnCeremonyService implements PasskeyCeremonyServiceContract
{
    public function beginRegistration(Authenticatable $actor, string $rpId, string $rpName): array
    {
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [
            'challenge' => $challenge,
            'rp' => ['id' => $rpId, 'name' => $rpName],
            'user' => [
                'id' => rtrim(strtr(base64_encode((string) $actor->getAuthIdentifier()), '+/', '-_'), '='),
                'name' => (string) ($actor->email ?? $actor->getAuthIdentifier()),
                'displayName' => (string) ($actor->name ?? $actor->getAuthIdentifier()),
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'userVerification' => 'required',
            ],
            'timeout' => 120000,
        ];
    }

    public function beginAssertion(string $rpId, ?Authenticatable $actor = null): array
    {
        return [
            'challenge' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'rpId' => $rpId,
            'userVerification' => 'required',
            'timeout' => 120000,
        ];
    }
}
