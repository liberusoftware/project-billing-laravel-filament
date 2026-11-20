<?php

declare(strict_types=1);

namespace Liberu\Payment\Core\Services;

use Liberu\Payment\Contracts\Contracts\MerchantWebhookVerifierContract;
use Liberu\Payment\Contracts\Contracts\PaymentCaptureAndRefundContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Contracts\Contracts\PaymentIntentServiceContract;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\MerchantWebhookEventDto;
use Liberu\Payment\Contracts\DTOs\PaymentCaptureResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentIntentResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentRefundResultDto;
use Liberu\Payment\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;

final readonly class PaymentOrchestrator
{
    public function __construct(private PaymentGatewayRegistryContract $registry) {}

    public function createIntent(CreatePaymentIntentCommand $command): PaymentIntentResultDto
    {
        $adapter = $this->registry->resolve($command->account->providerKey);
        if (! $adapter instanceof PaymentIntentServiceContract) {
            throw new \RuntimeException("Adapter [{$command->account->providerKey}] does not support payment intents.");
        }

        return $adapter->createIntent($command);
    }

    public function capture(CapturePaymentCommand $command): PaymentCaptureResultDto
    {
        $adapter = $this->registry->resolve($command->account->providerKey);
        if (! $adapter instanceof PaymentCaptureAndRefundContract) {
            throw new \RuntimeException("Adapter [{$command->account->providerKey}] does not support captures.");
        }

        return $adapter->capture($command);
    }

    public function refund(RefundPaymentCommand $command): PaymentRefundResultDto
    {
        $adapter = $this->registry->resolve($command->account->providerKey);
        if (! $adapter instanceof PaymentCaptureAndRefundContract) {
            throw new \RuntimeException("Adapter [{$command->account->providerKey}] does not support refunds.");
        }

        return $adapter->refund($command);
    }

    public function verifyWebhook(RawWebhookPayloadDto $payload): MerchantWebhookEventDto
    {
        $adapter = $this->registry->resolve($payload->providerKey);
        if (! $adapter instanceof MerchantWebhookVerifierContract) {
            throw new \RuntimeException("Adapter [{$payload->providerKey}] does not support webhook verification.");
        }

        return $adapter->verifyAndParse($payload);
    }
}
