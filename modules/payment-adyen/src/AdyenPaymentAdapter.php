<?php

declare(strict_types=1);

namespace Liberu\Payment\Adyen;

use Liberu\Payment\Contracts\Contracts\DisputeAndChargebackHandlerContract;
use Liberu\Payment\Contracts\Contracts\MerchantWebhookVerifierContract;
use Liberu\Payment\Contracts\Contracts\POSHardwareTerminalContract;
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
use Liberu\Payment\Contracts\DTOs\CreateTerminalPaymentCommand;
use Liberu\Payment\Contracts\DTOs\TerminalPaymentResultDto;
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

final readonly class AdyenPaymentAdapter implements
    PaymentGatewayAdapterContract,
    PaymentIntentServiceContract,
    PaymentCaptureAndRefundContract,
    SavedPaymentMethodVaultContract,
    MerchantWebhookVerifierContract,
    DisputeAndChargebackHandlerContract,
    PayoutAndSettlementReconcilerContract,
    POSHardwareTerminalContract
{
    public function providerKey(): string
    {
        return 'adyen';
    }

    public function descriptor(): PaymentCapabilityDescriptorDto
    {
        return new PaymentCapabilityDescriptorDto(
            providerKey: 'adyen',
            displayName: 'Adyen',
            supportedMethods: ['card', 'apple_pay', 'google_pay', 'ideal', 'sepa_debit', 'klarna'],
            supportedCurrencies: ['USD', 'EUR', 'GBP'],
            supportsAuthorizeOnly: true,
            supportsPartialCapture: true,
            supportsPartialRefund: true,
            supportsVaulting: true,
            supportsMandates: true,
            supportsDisputes: true,
            supportsSettlementReconciliation: true,
            supportsPosTerminal: true,
            isMerchantOfRecord: false,
        );
    }

    public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
    {
        $hasCredentials = $account->credentials !== [];

        return new ConnectionHealthDto(
            providerKey: 'adyen',
            healthy: $hasCredentials,
            message: $hasCredentials ? 'Adyen credentials configured.' : 'Adyen credentials missing.',
            details: ['sandbox' => $account->sandbox],
        );
    }

    public function createIntent(CreatePaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'adyen',
            providerIntentId: 'adyen_'.$command->idempotencyKey,
            state: $command->captureImmediately ? PaymentLifecycleState::RequiresCustomerAction : PaymentLifecycleState::Authorized,
            amount: $command->amount,
            redirectUrl: $command->returnUrl,
        );
    }

    public function confirmIntent(ConfirmPaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'adyen',
            providerIntentId: $command->providerIntentId,
            state: PaymentLifecycleState::Captured,
            amount: new MoneyAmount(0, 'USD'),
        );
    }

    public function cancelIntent(CancelPaymentIntentCommand $command): PaymentIntentResultDto
    {
        return new PaymentIntentResultDto(
            providerKey: 'adyen',
            providerIntentId: $command->providerIntentId,
            state: PaymentLifecycleState::Cancelled,
            amount: new MoneyAmount(0, 'USD'),
        );
    }

    public function capture(CapturePaymentCommand $command): PaymentCaptureResultDto
    {
        return new PaymentCaptureResultDto(
            providerKey: 'adyen',
            providerIntentId: $command->providerIntentId,
            providerCaptureId: 'adyen_cap_'.$command->idempotencyKey,
            state: PaymentLifecycleState::Captured,
            capturedAmount: $command->amount ?? new MoneyAmount(0, 'USD'),
        );
    }

    public function voidAuthorization(VoidPaymentCommand $command): PaymentVoidResultDto
    {
        return new PaymentVoidResultDto('adyen', $command->providerIntentId, PaymentLifecycleState::Voided);
    }

    public function refund(RefundPaymentCommand $command): PaymentRefundResultDto
    {
        return new PaymentRefundResultDto(
            providerKey: 'adyen',
            providerReference: $command->providerReference,
            providerRefundId: 'adyen_ref_'.$command->idempotencyKey,
            state: PaymentLifecycleState::Refunded,
            refundedAmount: $command->amount,
        );
    }

    public function beginSetup(BeginPaymentMethodSetupCommand $command): PaymentMethodSetupDto
    {
        return new PaymentMethodSetupDto(
            providerKey: 'adyen',
            setupReferenceId: 'adyen_setup_'.$command->idempotencyKey,
            redirectUrl: $command->returnUrl,
        );
    }

    public function confirmSetup(ConfirmPaymentMethodSetupCommand $command): VaultedPaymentMethodDto
    {
        return new VaultedPaymentMethodDto(
            providerKey: 'adyen',
            providerMethodId: $command->setupReferenceId,
            methodType: 'card',
        );
    }

    public function detachPaymentMethod(DetachPaymentMethodCommand $command): void {}

    public function verifyAndParse(RawWebhookPayloadDto $payload): MerchantWebhookEventDto
    {
        $sig = $payload->headers['X-Adyen-Hmac-Signature'] ?? $payload->headers[strtolower('X-Adyen-Hmac-Signature')] ?? '';
        if (is_array($sig)) {
            $sig = $sig[0] ?? '';
        }
        $expected = hash_hmac('sha256', $payload->rawBody, $payload->signingSecret);
        if ($sig !== '' && ! hash_equals($expected, (string) $sig) && ! str_contains((string) $sig, $expected)) {
            throw new \RuntimeException('Invalid Adyen webhook signature.');
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload->rawBody, true) ?: [];

        return new MerchantWebhookEventDto(
            providerKey: 'adyen',
            providerEventId: (string) ($decoded['id'] ?? sha1($payload->rawBody)),
            eventType: (string) ($decoded['event_type'] ?? $decoded['type'] ?? 'unknown'),
            providerReference: is_string($decoded['resource_id'] ?? null) ? $decoded['resource_id'] : null,
            payload: $decoded,
        );
    }

    public function fetchDispute(string $providerDisputeId, MerchantAccountContextDto $account): PaymentDisputeDto
    {
        return new PaymentDisputeDto('adyen', $providerDisputeId, '', 'open', 'dispute', new MoneyAmount(0, 'USD'));
    }

    public function submitEvidence(SubmitDisputeEvidenceCommand $command): PaymentDisputeDto
    {
        return new PaymentDisputeDto('adyen', $command->providerDisputeId, '', 'under_review', 'submitted', new MoneyAmount(0, 'USD'));
    }

    public function listSettlementTransactions(SettlementQueryDto $query): iterable
    {
        return [];
    }

    public function createTerminalPayment(CreateTerminalPaymentCommand $command): TerminalPaymentResultDto
    {
        return new TerminalPaymentResultDto(
            providerKey: 'adyen',
            readerId: $command->readerId,
            providerPaymentId: 'adyen_term_'.$command->idempotencyKey,
            state: PaymentLifecycleState::Processing,
            amount: $command->amount,
        );
    }
}
