<?php

declare(strict_types=1);

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
use Liberu\Payment\Contracts\PaymentContractsServiceProvider;
use Tests\ModulePackageTestCase;

uses(ModulePackageTestCase::class);

it('registers the payment contracts service provider', function () {
    $provider = new PaymentContractsServiceProvider(app());
    $provider->register();

    expect($provider)->toBeInstanceOf(PaymentContractsServiceProvider::class);
});

it('normalizes currency codes and rejects invalid ISO 4217 codes in MoneyAmount', function () {
    $money = new MoneyAmount(2500, ' usd ');

    expect($money->amountMinor)->toBe(2500)
        ->and($money->currency)->toBe('USD');

    expect(fn () => new MoneyAmount(100, ''))->toThrow(InvalidArgumentException::class, 'Currency must be a 3-letter ISO 4217 code.');
    expect(fn () => new MoneyAmount(100, 'US'))->toThrow(InvalidArgumentException::class, 'Currency must be a 3-letter ISO 4217 code.');
    expect(fn () => new MoneyAmount(100, 'USDT'))->toThrow(InvalidArgumentException::class, 'Currency must be a 3-letter ISO 4217 code.');
});

it('exposes all payment lifecycle state cases', function () {
    expect(PaymentLifecycleState::cases())->toHaveCount(16)
        ->and(PaymentLifecycleState::Draft->value)->toBe('draft')
        ->and(PaymentLifecycleState::RequiresPaymentMethod->value)->toBe('requires_payment_method')
        ->and(PaymentLifecycleState::RequiresCustomerAction->value)->toBe('requires_customer_action')
        ->and(PaymentLifecycleState::Processing->value)->toBe('processing')
        ->and(PaymentLifecycleState::Authorized->value)->toBe('authorized')
        ->and(PaymentLifecycleState::PartiallyCaptured->value)->toBe('partially_captured')
        ->and(PaymentLifecycleState::Captured->value)->toBe('captured')
        ->and(PaymentLifecycleState::Voided->value)->toBe('voided')
        ->and(PaymentLifecycleState::PartiallyRefunded->value)->toBe('partially_refunded')
        ->and(PaymentLifecycleState::Refunded->value)->toBe('refunded')
        ->and(PaymentLifecycleState::Disputed->value)->toBe('disputed')
        ->and(PaymentLifecycleState::DisputeWon->value)->toBe('dispute_won')
        ->and(PaymentLifecycleState::DisputeLost->value)->toBe('dispute_lost')
        ->and(PaymentLifecycleState::Failed->value)->toBe('failed')
        ->and(PaymentLifecycleState::Cancelled->value)->toBe('cancelled')
        ->and(PaymentLifecycleState::Expired->value)->toBe('expired');
});

it('constructs all payment contract command and result DTOs accurately', function () {
    $account = new MerchantAccountContextDto(
        tenantId: 'tenant_1',
        providerKey: 'stripe',
        credentials: ['secret_key' => 'sk_test_123'],
        sandbox: true,
        apiVersion: '2026-02-25.clover',
    );
    $amount = new MoneyAmount(1500, 'eur');

    $beginSetup = new BeginPaymentMethodSetupCommand($account, 'cus_1', 'idem_1', 'https://example.test/return', ['k' => 'v']);
    $cancelIntent = new CancelPaymentIntentCommand($account, 'pi_1', 'idem_2', 'requested_by_customer');
    $capture = new CapturePaymentCommand($account, 'pi_1', 'idem_3', $amount);
    $confirmIntent = new ConfirmPaymentIntentCommand($account, 'pi_1', 'idem_4', 'pm_1', ['return_url' => 'https://example.test']);
    $confirmSetup = new ConfirmPaymentMethodSetupCommand($account, 'seti_1', 'idem_5');
    $health = new ConnectionHealthDto('stripe', true, 'OK', ['livemode' => false]);
    $createIntent = new CreatePaymentIntentCommand($account, $amount, 'idem_6', 'pm_1', 'cus_1', 'Order #1', 'https://example.test', false, ['order' => 1]);
    $createTerminal = new CreateTerminalPaymentCommand($account, 'tmr_1', $amount, 'idem_7', ['pos' => true]);
    $detach = new DetachPaymentMethodCommand($account, 'pm_1');
    $webhookEvent = new MerchantWebhookEventDto('stripe', 'evt_1', 'payment_intent.succeeded', 'pi_1', PaymentLifecycleState::Captured, $amount, ['id' => 'evt_1']);
    $descriptor = new PaymentCapabilityDescriptorDto('stripe', 'Stripe', ['card'], ['USD', 'EUR']);
    $captureResult = new PaymentCaptureResultDto('stripe', 'pi_1', 'ch_1', PaymentLifecycleState::Captured, $amount);
    $dispute = new PaymentDisputeDto('stripe', 'dp_1', 'pi_1', 'needs_response', 'fraudulent', $amount);
    $intentResult = new PaymentIntentResultDto('stripe', 'pi_1', PaymentLifecycleState::Authorized, $amount, 'secret_1', 'https://example.test/3ds', ['type' => 'redirect'], ['raw' => true]);
    $setupResult = new PaymentMethodSetupDto('stripe', 'seti_1', 'seti_secret', 'https://example.test/setup', ['mode' => 'setup']);
    $refundResult = new PaymentRefundResultDto('stripe', 'pi_1', 're_1', PaymentLifecycleState::Refunded, $amount);
    $voidResult = new PaymentVoidResultDto('stripe', 'pi_1', PaymentLifecycleState::Voided);
    $rawWebhook = new RawWebhookPayloadDto('stripe', '{}', ['stripe-signature' => 'sig'], 'whsec_123');
    $refundCmd = new RefundPaymentCommand($account, 'pi_1', $amount, 'idem_8', 'duplicate');
    $settlementQuery = new SettlementQueryDto($account, 'po_1', '2026-01-01T00:00:00Z', '2026-01-31T23:59:59Z', 50);
    $settlementTxn = new SettlementTransactionDto('stripe', 'txn_1', 'ch_1', $amount, new MoneyAmount(50, 'EUR'), new MoneyAmount(1450, 'EUR'), 'charge', '2026-01-15T12:00:00+00:00');
    $submitEvidence = new SubmitDisputeEvidenceCommand($account, 'dp_1', ['customer_name' => 'Jane Doe'], true);
    $terminalResult = new TerminalPaymentResultDto('stripe', 'tmr_1', 'pi_term_1', PaymentLifecycleState::Processing, $amount);
    $vaulted = new VaultedPaymentMethodDto('stripe', 'pm_1', 'card', 'cus_1', 'mandate_1', ['last4' => '4242']);
    $voidCmd = new VoidPaymentCommand($account, 'pi_1', 'idem_9', 'abandoned');

    expect($account->tenantId)->toBe('tenant_1')
        ->and($beginSetup->customerReference)->toBe('cus_1')
        ->and($cancelIntent->reason)->toBe('requested_by_customer')
        ->and($capture->amount)->toBe($amount)
        ->and($confirmIntent->paymentMethodToken)->toBe('pm_1')
        ->and($confirmSetup->setupReferenceId)->toBe('seti_1')
        ->and($health->healthy)->toBeTrue()
        ->and($createIntent->captureImmediately)->toBeFalse()
        ->and($createTerminal->readerId)->toBe('tmr_1')
        ->and($detach->providerMethodId)->toBe('pm_1')
        ->and($webhookEvent->targetState)->toBe(PaymentLifecycleState::Captured)
        ->and($descriptor->providerKey)->toBe('stripe')
        ->and($captureResult->providerCaptureId)->toBe('ch_1')
        ->and($dispute->providerDisputeId)->toBe('dp_1')
        ->and($intentResult->clientSecret)->toBe('secret_1')
        ->and($setupResult->setupReferenceId)->toBe('seti_1')
        ->and($refundResult->providerRefundId)->toBe('re_1')
        ->and($voidResult->state)->toBe(PaymentLifecycleState::Voided)
        ->and($rawWebhook->signingSecret)->toBe('whsec_123')
        ->and($refundCmd->reason)->toBe('duplicate')
        ->and($settlementQuery->limit)->toBe(50)
        ->and($settlementTxn->netAmount->amountMinor)->toBe(1450)
        ->and($submitEvidence->submit)->toBeTrue()
        ->and($terminalResult->readerId)->toBe('tmr_1')
        ->and($vaulted->displayDetails)->toBe(['last4' => '4242'])
        ->and($voidCmd->reason)->toBe('abandoned');
});
