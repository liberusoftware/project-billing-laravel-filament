<?php

declare(strict_types=1);

namespace Liberu\Payment\Paddle;

use Liberu\Payment\Contracts\Contracts\DisputeAndChargebackHandlerContract;
use Liberu\Payment\Contracts\Contracts\MerchantWebhookVerifierContract;
use Liberu\Payment\Contracts\Contracts\PaymentCaptureAndRefundContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentIntentServiceContract;
use Liberu\Payment\Contracts\Contracts\PayoutAndSettlementReconcilerContract;
use Liberu\Payment\Contracts\Contracts\SavedPaymentMethodVaultContract;
use Liberu\Payment\Contracts\DTOs\BeginPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\CancelPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\DetachPaymentMethodCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MerchantWebhookEventDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\PaymentCapabilityDescriptorDto;
use Liberu\Payment\Contracts\DTOs\PaymentCaptureResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentDisputeDto;
use Liberu\Payment\Contracts\DTOs\PaymentIntentResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentMethodSetupDto;
use Liberu\Payment\Contracts\DTOs\PaymentRefundResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentVoidResultDto;
use Liberu\Payment\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\DTOs\SettlementQueryDto;
use Liberu\Payment\Contracts\DTOs\SubmitDisputeEvidenceCommand;
use Liberu\Payment\Contracts\DTOs\VaultedPaymentMethodDto;
use Liberu\Payment\Contracts\DTOs\VoidPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;

final readonly class PaddlePaymentAdapter implements
    PaymentGatewayAdapterContract,
    PaymentIntentServiceContract,
    PaymentCaptureAndRefundContract,
    SavedPaymentMethodVaultContract,
    MerchantWebhookVerifierContract,
    DisputeAndChargebackHandlerContract,
    PayoutAndSettlementReconcilerContract
{
    public function providerKey(): string
    {
        return 'paddle';
    }

    public function descriptor(): PaymentCapabilityDescriptorDto
    {
        return new PaymentCapabilityDescriptorDto(
            providerKey: 'paddle',
            displayName: 'Paddle Billing',
            supportedMethods: ['card', 'paypal', 'apple_pay', 'google_pay'],
            supportedCurrencies: ['USD', 'EUR', 'GBP'],
            supportsAuthorizeOnly: true,
            supportsPartialCapture: true,
            supportsPartialRefund: true,
            supportsVaulting: true,
            supportsMandates: false,
            supportsDisputes: true,
            supportsSettlementReconciliation: true,
            supportsPosTerminal: false,
            isMerchantOfRecord: true,
        );
    }

    public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
    {
        $hasCredentials = $account->credentials !== [];

        return new ConnectionHealthDto(
            providerKey: 'paddle',
            healthy: $hasCredentials,
            message: $hasCredentials ? 'Paddle Billing credentials configured.' : 'Paddle Billing credentials missing.',
            details: ['sandbox' => $account->sandbox],
        );
    }

    public function createIntent(CreatePaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'paddle',
            providerIntentId: 'paddle_'.$command->idempotencyKey,
            state: $command->captureImmediately ? PaymentLifecycleState::RequiresCustomerAction : PaymentLifecycleState::Authorized,
            amount: $command->amount,
            redirectUrl: $command->returnUrl,
        );
    }

    public function confirmIntent(ConfirmPaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'paddle',
            providerIntentId: $command->providerIntentId,
            state: PaymentLifecycleState::Captured,
            amount: new MoneyAmount(0, 'USD'),
        );
    }

    public function cancelIntent(CancelPaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'paddle',
            providerIntentId: $command->providerIntentId,
            state: PaymentLifecycleState::Cancelled,
            amount: new MoneyAmount(0, 'USD'),
        );
    }

    public function capture(CapturePaymentCommand $command): PaymentCaptureResultDto
    {
        return new PaymentCaptureResultDto(
            providerKey: 'paddle',
            providerIntentId: $command->providerIntentId,
            providerCaptureId: 'paddle_cap_'.$command->idempotencyKey,
            state: PaymentLifecycleState::Captured,
            capturedAmount: $command->amount ?? new MoneyAmount(0, 'USD'),
        );
    }

    public function voidAuthorization(VoidPaymentCommand $command): PaymentVoidResultDto
    {
        return new PaymentVoidResultDto('paddle', $command->providerIntentId, PaymentLifecycleState::Voided);
    }

    public function refund(RefundPaymentCommand $command): PaymentRefundResultDto
    {
        return new PaymentRefundResultDto(
            providerKey: 'paddle',
            providerReference: $command->providerReference,
            providerRefundId: 'paddle_ref_'.$command->idempotencyKey,
            state: PaymentLifecycleState::Refunded,
            refundedAmount: $command->amount,
        );
    }

    public function beginSetup(BeginPaymentMethodSetupCommand $command): PaymentMethodSetupDto
    {
        return new PaymentMethodSetupDto(
            providerKey: 'paddle',
            setupReferenceId: 'paddle_setup_'.$command->idempotencyKey,
            redirectUrl: $command->returnUrl,
        );
    }

    public function confirmSetup(ConfirmPaymentMethodSetupCommand $command): VaultedPaymentMethodDto
    {
        return new VaultedPaymentMethodDto(
            providerKey: 'paddle',
            providerMethodId: $command->setupReferenceId,
            methodType: 'card',
        );
    }

    public function detachPaymentMethod(DetachPaymentMethodCommand $command): void {}

    public function verifyAndParse(RawWebhookPayloadDto $payload): MerchantWebhookEventDto
    {
        $sig = $payload->headers['Paddle-Signature'] ?? $payload->headers[strtolower('Paddle-Signature')] ?? '';
        if (is_array($sig)) {
            $sig = $sig[0] ?? '';
        }
        $expected = hash_hmac('sha256', $payload->rawBody, $payload->signingSecret);
        if ($sig !== '' && ! hash_equals($expected, (string) $sig) && ! str_contains((string) $sig, $expected)) {
            throw new \RuntimeException('Invalid Paddle Billing webhook signature.');
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload->rawBody, true) ?: [];

        return new MerchantWebhookEventDto(
            providerKey: 'paddle',
            providerEventId: (string) ($decoded['id'] ?? sha1($payload->rawBody)),
            eventType: (string) ($decoded['event_type'] ?? $decoded['type'] ?? 'unknown'),
            providerReference: is_string($decoded['resource_id'] ?? null) ? $decoded['resource_id'] : null,
            payload: $decoded,
        );
    }

    public function fetchDispute(string $providerDisputeId, MerchantAccountContextDto $account): PaymentDisputeDto
    {
        return new PaymentDisputeDto('paddle', $providerDisputeId, '', 'open', 'dispute', new MoneyAmount(0, 'USD'));
    }

    public function submitEvidence(SubmitDisputeEvidenceCommand $command): PaymentDisputeDto
    {
        return new PaymentDisputeDto('paddle', $command->providerDisputeId, '', 'under_review', 'submitted', new MoneyAmount(0, 'USD'));
    }

    public function listSettlementTransactions(SettlementQueryDto $query): iterable
    {
        return [];
    }
}
