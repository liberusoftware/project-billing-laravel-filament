<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class SubmitDisputeEvidenceCommand
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public MerchantAccountContextDto $account,
        public string $providerDisputeId,
        public array $evidence,
        public bool $submit = true,
    ) {}
}
