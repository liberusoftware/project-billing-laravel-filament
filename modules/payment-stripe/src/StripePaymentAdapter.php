<?php

declare(strict_types=1);

namespace Liberu\Payment\Stripe;

use Liberu\Payment\Contracts\Contracts\DisputeAndChargebackHandlerContract;
use Liberu\Payment\Contracts\Contracts\MerchantWebhookVerifierContract;
use Liberu\Payment\Contracts\Contracts\PaymentCaptureAndRefundContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentIntentServiceContract;
use Liberu\Payment\Contracts\Contracts\PayoutAndSettlementReconcilerContract;
use Liberu\Payment\Contracts\Contracts\POSHardwareTerminalContract;
use Liberu\Payment\Contracts\Contracts\SavedPaymentMethodVaultContract;
use Liberu\Payment\Contracts\DTOs\BeginPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\CancelPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CreateTerminalPaymentCommand;
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
use Liberu\Payment\Contracts\DTOs\SettlementTransactionDto;
use Liberu\Payment\Contracts\DTOs\SubmitDisputeEvidenceCommand;
use Liberu\Payment\Contracts\DTOs\TerminalPaymentResultDto;
use Liberu\Payment\Contracts\DTOs\VaultedPaymentMethodDto;
use Liberu\Payment\Contracts\DTOs\VoidPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;
use Stripe\StripeClient;
use Stripe\Webhook;

final readonly class StripePaymentAdapter implements DisputeAndChargebackHandlerContract, MerchantWebhookVerifierContract, PaymentCaptureAndRefundContract, PaymentGatewayAdapterContract, PaymentIntentServiceContract, PayoutAndSettlementReconcilerContract, POSHardwareTerminalContract, SavedPaymentMethodVaultContract
{
    public const DEFAULT_API_VERSION = '2026-02-25.clover';

    public function providerKey(): string
    {
        return 'stripe';
    }

    public function descriptor(): PaymentCapabilityDescriptorDto
    {
        return new PaymentCapabilityDescriptorDto(
            providerKey: 'stripe',
            displayName: 'Stripe',
            supportedMethods: ['card', 'apple_pay', 'google_pay', 'link', 'sepa_debit', 'us_bank_account', 'klarna'],
            supportedCurrencies: ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY'],
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
        $client = $this->client($account);
        $balance = $client->balance->retrieve();

        return new ConnectionHealthDto(
            providerKey: 'stripe',
            healthy: true,
            message: 'Stripe API connection verified.',
            details: ['livemode' => (bool) ($balance->livemode ?? ! $account->sandbox)],
        );
    }

    public function createIntent(CreatePaymentIntentCommand $command): PaymentIntentResultDto
    {
        $params = [
            'amount' => $command->amount->amountMinor,
            'currency' => strtolower($command->amount->currency),
            'capture_method' => $command->captureImmediately ? 'automatic' : 'manual',
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => array_merge($command->metadata, ['tenant_id' => $command->account->tenantId]),
        ];
        if ($command->paymentMethodToken !== null) {
            $params['payment_method'] = $command->paymentMethodToken;
            $params['confirm'] = true;
            if ($command->returnUrl !== null) {
                $params['return_url'] = $command->returnUrl;
            }
        }
        if ($command->customerReference !== null) {
            $params['customer'] = $command->customerReference;
        }
        if ($command->description !== null) {
            $params['description'] = $command->description;
        }

        $intent = $this->client($command->account)->paymentIntents->create(
            $params,
            ['idempotency_key' => $command->idempotencyKey],
        );

        return new PaymentIntentResultDto(
            providerKey: 'stripe',
            providerIntentId: (string) $intent->id,
            state: $this->mapIntentStatus((string) $intent->status),
            amount: new MoneyAmount((int) $intent->amount, strtoupper((string) $intent->currency)),
            clientSecret: is_string($intent->client_secret) ? $intent->client_secret : null,
            redirectUrl: is_string($intent->next_action?->redirect_to_url?->url ?? null) ? $intent->next_action->redirect_to_url->url : null,
        );
    }

    public function confirmIntent(ConfirmPaymentIntentCommand $command): PaymentIntentResultDto
    {
        $params = $command->payload;
        if ($command->paymentMethodToken !== null) {
            $params['payment_method'] = $command->paymentMethodToken;
        }

        $intent = $this->client($command->account)->paymentIntents->confirm(
            $command->providerIntentId,
            $params,
            ['idempotency_key' => $command->idempotencyKey],
        );

        return new PaymentIntentResultDto(
            providerKey: 'stripe',
            providerIntentId: (string) $intent->id,
            state: $this->mapIntentStatus((string) $intent->status),
            amount: new MoneyAmount((int) $intent->amount, strtoupper((string) $intent->currency)),
            clientSecret: is_string($intent->client_secret) ? $intent->client_secret : null,
        );
    }

    public function cancelIntent(CancelPaymentIntentCommand $command): PaymentIntentResultDto
    {
        $params = $command->reason !== null ? ['cancellation_reason' => $command->reason] : [];
        $intent = $this->client($command->account)->paymentIntents->cancel(
            $command->providerIntentId,
            $params,
            ['idempotency_key' => $command->idempotencyKey],
        );

        return new PaymentIntentResultDto(
            providerKey: 'stripe',
            providerIntentId: (string) $intent->id,
            state: PaymentLifecycleState::Cancelled,
            amount: new MoneyAmount((int) $intent->amount, strtoupper((string) $intent->currency)),
        );
    }

    public function capture(CapturePaymentCommand $command): PaymentCaptureResultDto
    {
        $params = $command->amount !== null ? ['amount_to_capture' => $command->amount->amountMinor] : [];
        $intent = $this->client($command->account)->paymentIntents->capture(
            $command->providerIntentId,
            $params,
            ['idempotency_key' => $command->idempotencyKey],
        );
        $received = (int) ($intent->amount_received ?? $intent->amount);

        return new PaymentCaptureResultDto(
            providerKey: 'stripe',
            providerIntentId: (string) $intent->id,
            providerCaptureId: (string) ($intent->latest_charge ?? $intent->id),
            state: $received < (int) $intent->amount ? PaymentLifecycleState::PartiallyCaptured : PaymentLifecycleState::Captured,
            capturedAmount: new MoneyAmount($received, strtoupper((string) $intent->currency)),
        );
    }

    public function voidAuthorization(VoidPaymentCommand $command): PaymentVoidResultDto
    {
        $this->client($command->account)->paymentIntents->cancel(
            $command->providerIntentId,
            [],
            ['idempotency_key' => $command->idempotencyKey],
        );

        return new PaymentVoidResultDto('stripe', $command->providerIntentId, PaymentLifecycleState::Voided);
    }

    public function refund(RefundPaymentCommand $command): PaymentRefundResultDto
    {
        $refund = $this->client($command->account)->refunds->create([
            'payment_intent' => $command->providerReference,
            'amount' => $command->amount->amountMinor,
            'reason' => $command->reason,
        ], ['idempotency_key' => $command->idempotencyKey]);

        return new PaymentRefundResultDto(
            providerKey: 'stripe',
            providerReference: $command->providerReference,
            providerRefundId: (string) $refund->id,
            state: PaymentLifecycleState::Refunded,
            refundedAmount: new MoneyAmount((int) $refund->amount, strtoupper((string) $refund->currency)),
        );
    }

    public function beginSetup(BeginPaymentMethodSetupCommand $command): PaymentMethodSetupDto
    {
        $setup = $this->client($command->account)->setupIntents->create([
            'customer' => $command->customerReference,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => $command->metadata,
        ], ['idempotency_key' => $command->idempotencyKey]);

        return new PaymentMethodSetupDto(
            providerKey: 'stripe',
            setupReferenceId: (string) $setup->id,
            clientSecret: is_string($setup->client_secret) ? $setup->client_secret : null,
        );
    }

    public function confirmSetup(ConfirmPaymentMethodSetupCommand $command): VaultedPaymentMethodDto
    {
        $setup = $this->client($command->account)->setupIntents->retrieve($command->setupReferenceId);

        return new VaultedPaymentMethodDto(
            providerKey: 'stripe',
            providerMethodId: (string) $setup->payment_method,
            methodType: 'card',
            customerReference: is_string($setup->customer) ? $setup->customer : null,
            mandateReference: is_string($setup->mandate) ? $setup->mandate : null,
        );
    }

    public function detachPaymentMethod(DetachPaymentMethodCommand $command): void
    {
        $this->client($command->account)->paymentMethods->detach($command->providerMethodId);
    }

    public function verifyAndParse(RawWebhookPayloadDto $payload): MerchantWebhookEventDto
    {
        $signature = $payload->headers['stripe-signature'] ?? $payload->headers['Stripe-Signature'] ?? '';
        if (is_array($signature)) {
            $signature = $signature[0] ?? '';
        }
        $event = Webhook::constructEvent($payload->rawBody, (string) $signature, $payload->signingSecret);
        $object = $event->data->object;

        return new MerchantWebhookEventDto(
            providerKey: 'stripe',
            providerEventId: (string) $event->id,
            eventType: (string) $event->type,
            providerReference: is_string($object->id ?? null) ? $object->id : null,
            targetState: match ((string) $event->type) {
                'payment_intent.succeeded' => PaymentLifecycleState::Captured,
                'payment_intent.payment_failed' => PaymentLifecycleState::Failed,
                'payment_intent.canceled' => PaymentLifecycleState::Cancelled,
                'charge.refunded' => PaymentLifecycleState::Refunded,
                'charge.dispute.created' => PaymentLifecycleState::Disputed,
                default => null,
            },
            payload: $event->toArray(),
        );
    }

    public function fetchDispute(string $providerDisputeId, MerchantAccountContextDto $account): PaymentDisputeDto
    {
        $dispute = $this->client($account)->disputes->retrieve($providerDisputeId);

        return new PaymentDisputeDto(
            providerKey: 'stripe',
            providerDisputeId: (string) $dispute->id,
            providerPaymentReference: (string) ($dispute->payment_intent ?? $dispute->charge),
            status: (string) $dispute->status,
            reason: (string) $dispute->reason,
            amount: new MoneyAmount((int) $dispute->amount, strtoupper((string) $dispute->currency)),
        );
    }

    public function submitEvidence(SubmitDisputeEvidenceCommand $command): PaymentDisputeDto
    {
        $dispute = $this->client($command->account)->disputes->update($command->providerDisputeId, [
            'evidence' => $command->evidence,
            'submit' => $command->submit,
        ]);

        return new PaymentDisputeDto(
            providerKey: 'stripe',
            providerDisputeId: (string) $dispute->id,
            providerPaymentReference: (string) ($dispute->payment_intent ?? $dispute->charge),
            status: (string) $dispute->status,
            reason: (string) $dispute->reason,
            amount: new MoneyAmount((int) $dispute->amount, strtoupper((string) $dispute->currency)),
        );
    }

    public function listSettlementTransactions(SettlementQueryDto $query): iterable
    {
        $params = ['limit' => $query->limit];
        if ($query->payoutId !== null) {
            $params['payout'] = $query->payoutId;
        }
        $txns = $this->client($query->account)->balanceTransactions->all($params);
        $items = [];
        foreach ($txns->autoPagingIterator() as $txn) {
            $currency = strtoupper((string) $txn->currency);
            $items[] = new SettlementTransactionDto(
                providerKey: 'stripe',
                settlementTransactionId: (string) $txn->id,
                providerPaymentReference: is_string($txn->source) ? $txn->source : null,
                grossAmount: new MoneyAmount((int) $txn->amount, $currency),
                feeAmount: new MoneyAmount((int) $txn->fee, $currency),
                netAmount: new MoneyAmount((int) $txn->net, $currency),
                type: (string) $txn->type,
                occurredAtIso8601: gmdate('c', (int) $txn->created),
            );
        }

        return $items;
    }

    public function createTerminalPayment(CreateTerminalPaymentCommand $command): TerminalPaymentResultDto
    {
        $intent = $this->client($command->account)->paymentIntents->create([
            'amount' => $command->amount->amountMinor,
            'currency' => strtolower($command->amount->currency),
            'payment_method_types' => ['card_present'],
            'capture_method' => 'automatic',
            'metadata' => $command->metadata,
        ], ['idempotency_key' => $command->idempotencyKey]);

        $this->client($command->account)->terminal->readers->processPaymentIntent($command->readerId, [
            'payment_intent' => $intent->id,
        ]);

        return new TerminalPaymentResultDto(
            providerKey: 'stripe',
            readerId: $command->readerId,
            providerPaymentId: (string) $intent->id,
            state: $this->mapIntentStatus((string) $intent->status),
            amount: $command->amount,
        );
    }

    private function client(MerchantAccountContextDto $account): StripeClient
    {
        $apiKey = (string) ($account->credentials['secret_key'] ?? config('services.stripe.secret', ''));

        return new StripeClient([
            'api_key' => $apiKey,
            'stripe_version' => $account->apiVersion ?? config('services.stripe.api_version', self::DEFAULT_API_VERSION),
            'max_network_retries' => 2,
        ]);
    }

    private function mapIntentStatus(string $status): PaymentLifecycleState
    {
        return match ($status) {
            'requires_payment_method' => PaymentLifecycleState::RequiresPaymentMethod,
            'requires_confirmation', 'requires_action' => PaymentLifecycleState::RequiresCustomerAction,
            'processing' => PaymentLifecycleState::Processing,
            'requires_capture' => PaymentLifecycleState::Authorized,
            'succeeded' => PaymentLifecycleState::Captured,
            'canceled' => PaymentLifecycleState::Cancelled,
            default => PaymentLifecycleState::Processing,
        };
    }
}
