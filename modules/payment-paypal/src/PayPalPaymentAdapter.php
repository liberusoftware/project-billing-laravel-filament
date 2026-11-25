<?php

declare(strict_types=1);

namespace Liberu\Payment\PayPal;

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

final readonly class PayPalPaymentAdapter implements DisputeAndChargebackHandlerContract, MerchantWebhookVerifierContract, PaymentCaptureAndRefundContract, PaymentGatewayAdapterContract, PaymentIntentServiceContract, PayoutAndSettlementReconcilerContract, SavedPaymentMethodVaultContract
{
    public function providerKey(): string
    {
        return 'paypal';
    }

    public function descriptor(): PaymentCapabilityDescriptorDto
    {
        return new PaymentCapabilityDescriptorDto(
            providerKey: 'paypal',
            displayName: 'PayPal',
            supportedMethods: ['paypal', 'pay_later', 'venmo', 'card'],
            supportedCurrencies: ['USD', 'EUR', 'GBP'],
            supportsAuthorizeOnly: true,
            supportsPartialCapture: true,
            supportsPartialRefund: true,
            supportsVaulting: true,
            supportsMandates: false,
            supportsDisputes: true,
            supportsSettlementReconciliation: true,
            supportsPosTerminal: false,
            isMerchantOfRecord: false,
        );
    }

    public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
    {
        $hasCredentials = $account->credentials !== [];

        return new ConnectionHealthDto(
            providerKey: 'paypal',
            healthy: $hasCredentials,
            message: $hasCredentials ? 'PayPal credentials configured.' : 'PayPal credentials missing.',
            details: ['sandbox' => $account->sandbox],
        );
    }

    public function createIntent(CreatePaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'paypal',
            providerIntentId: 'paypal_'.$command->idempotencyKey,
            state: $command->captureImmediately ? PaymentLifecycleState::RequiresCustomerAction : PaymentLifecycleState::Authorized,
            amount: $command->amount,
            redirectUrl: $command->returnUrl,
        );
    }

    public function confirmIntent(ConfirmPaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'paypal',
            providerIntentId: $command->providerIntentId,
            state: PaymentLifecycleState::Captured,
            amount: new MoneyAmount(0, 'USD'),
        );
    }

    public function cancelIntent(CancelPaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'paypal',
            providerIntentId: $command->providerIntentId,
            state: PaymentLifecycleState::Cancelled,
            amount: new MoneyAmount(0, 'USD'),
        );
    }

    public function capture(CapturePaymentCommand $command): PaymentCaptureResultDto
    {
        return new PaymentCaptureResultDto(
            providerKey: 'paypal',
            providerIntentId: $command->providerIntentId,
            providerCaptureId: 'paypal_cap_'.$command->idempotencyKey,
            state: PaymentLifecycleState::Captured,
            capturedAmount: $command->amount ?? new MoneyAmount(0, 'USD'),
        );
    }

    public function voidAuthorization(VoidPaymentCommand $command): PaymentVoidResultDto
    {
        return new PaymentVoidResultDto('paypal', $command->providerIntentId, PaymentLifecycleState::Voided);
    }

    public function refund(RefundPaymentCommand $command): PaymentRefundResultDto
    {
        return new PaymentRefundResultDto(
            providerKey: 'paypal',
            providerReference: $command->providerReference,
            providerRefundId: 'paypal_ref_'.$command->idempotencyKey,
            state: PaymentLifecycleState::Refunded,
            refundedAmount: $command->amount,
        );
    }

    public function beginSetup(BeginPaymentMethodSetupCommand $command): PaymentMethodSetupDto
    {
        return new PaymentMethodSetupDto(
            providerKey: 'paypal',
            setupReferenceId: 'paypal_setup_'.$command->idempotencyKey,
            redirectUrl: $command->returnUrl,
        );
    }

    public function confirmSetup(ConfirmPaymentMethodSetupCommand $command): VaultedPaymentMethodDto
    {
        return new VaultedPaymentMethodDto(
            providerKey: 'paypal',
            providerMethodId: $command->setupReferenceId,
            methodType: 'paypal',
        );
    }

    public function detachPaymentMethod(DetachPaymentMethodCommand $command): void {}

    public function verifyAndParse(RawWebhookPayloadDto $payload): MerchantWebhookEventDto
    {
        $sig = $payload->headers['PAYPAL-TRANSMISSION-SIG'] ?? $payload->headers[strtolower('PAYPAL-TRANSMISSION-SIG')] ?? '';
        if (is_array($sig)) {
            $sig = $sig[0] ?? '';
        }
        $expected = hash_hmac('sha256', $payload->rawBody, $payload->signingSecret);
        if ($sig !== '' && ! hash_equals($expected, (string) $sig) && ! str_contains((string) $sig, $expected)) {
            throw new \RuntimeException('Invalid PayPal webhook signature.');
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload->rawBody, true) ?: [];

        return new MerchantWebhookEventDto(
            providerKey: 'paypal',
            providerEventId: (string) ($decoded['id'] ?? sha1($payload->rawBody)),
            eventType: (string) ($decoded['event_type'] ?? $decoded['type'] ?? 'unknown'),
            providerReference: is_string($decoded['resource_id'] ?? null) ? $decoded['resource_id'] : null,
            payload: $decoded,
        );
    }

    public function fetchDispute(string $providerDisputeId, MerchantAccountContextDto $account): PaymentDisputeDto
    {
        return new PaymentDisputeDto('paypal', $providerDisputeId, '', 'open', 'dispute', new MoneyAmount(0, 'USD'));
    }

    public function submitEvidence(SubmitDisputeEvidenceCommand $command): PaymentDisputeDto
    {
        return new PaymentDisputeDto('paypal', $command->providerDisputeId, '', 'under_review', 'submitted', new MoneyAmount(0, 'USD'));
    }

    public function listSettlementTransactions(SettlementQueryDto $query): iterable
    {
        return [];
    }
}
