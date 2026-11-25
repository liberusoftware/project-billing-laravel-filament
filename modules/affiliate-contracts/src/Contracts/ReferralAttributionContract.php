<?php

declare(strict_types=1);

namespace Liberu\Affiliate\Contracts\Contracts;

interface ReferralAttributionContract
{
    public function issueReferralCode(string $tenantId, string $partnerId, string $programKey): string;

    /** @return array{partner_id:string,program_key:string}|null */
    public function resolveAttribution(string $tenantId, ?string $referralCode = null, ?string $touchToken = null): ?array;
}
