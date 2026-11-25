<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\PaymentDisputeDto;
use Liberu\Payment\Contracts\DTOs\SubmitDisputeEvidenceCommand;

interface DisputeAndChargebackHandlerContract
{
    public function fetchDispute(string $providerDisputeId, MerchantAccountContextDto $account): PaymentDisputeDto;

    public function submitEvidence(SubmitDisputeEvidenceCommand $command): PaymentDisputeDto;
}
