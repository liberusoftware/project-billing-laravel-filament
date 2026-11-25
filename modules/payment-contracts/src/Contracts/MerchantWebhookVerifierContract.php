<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\MerchantWebhookEventDto;
use Liberu\Payment\Contracts\DTOs\RawWebhookPayloadDto;

interface MerchantWebhookVerifierContract
{
    public function verifyAndParse(RawWebhookPayloadDto $payload): MerchantWebhookEventDto;
}
