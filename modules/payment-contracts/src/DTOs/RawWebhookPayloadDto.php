<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\DTOs;

final readonly class RawWebhookPayloadDto
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public string $providerKey,
        public string $rawBody,
        public array $headers,
        public string $signingSecret,
    ) {}
}
