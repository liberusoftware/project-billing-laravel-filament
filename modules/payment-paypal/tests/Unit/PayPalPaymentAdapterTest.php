<?php

declare(strict_types=1);

use Liberu\Payment\Contracts\Contracts\PaymentGatewayAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Contracts\DTOs\BeginPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\CancelPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\DetachPaymentMethodCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\DTOs\SettlementQueryDto;
use Liberu\Payment\Contracts\DTOs\SubmitDisputeEvidenceCommand;
use Liberu\Payment\Contracts\DTOs\VoidPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;
use Liberu\Payment\PayPal\PayPalPaymentAdapter;
use Liberu\Payment\PayPal\PayPalPaymentServiceProvider;
use Tests\ModulePackageTestCase;

uses(ModulePackageTestCase::class);

it('registers PayPalPaymentAdapter as singleton and into PaymentGatewayRegistryContract on boot', function () {
    $adapter = app(PayPalPaymentAdapter::class);
    expect($adapter)->toBe(app(PayPalPaymentAdapter::class));

    $items = [];
    $registry = new class($items) implements PaymentGatewayRegistryContract
    {
        public function __construct(public array &$items) {}

        public function register(PaymentGatewayAdapterContract $adapter): void
        {
            $this->items[$adapter->providerKey()] = $adapter;
        }

        public function has(string $providerKey): bool
        {
            return isset($this->items[$providerKey]);
        }

        public function resolve(string $providerKey): PaymentGatewayAdapterContract
        {
            return $this->items[$providerKey];
        }

        public function all(): array
        {
            return $this->items;
        }
    };

    app()->instance(PaymentGatewayRegistryContract::class, $registry);
    (new PayPalPaymentServiceProvider(app()))->boot();

    expect($registry->has('paypal'))->toBeTrue()
        ->and($registry->resolve('paypal'))->toBe($adapter);
});

it('exposes provider key, descriptor, and connection health', function () {
    $adapter = new PayPalPaymentAdapter();
    $descriptor = $adapter->descriptor();

    expect($adapter->providerKey())->toBe('paypal')
        ->and($descriptor->providerKey)->toBe('paypal')
        ->and($descriptor->displayName)->toBe('PayPal')
        ->and($descriptor->supportsPosTerminal)->toBeFalse();

    $healthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'paypal', ['client_id' => 'pp_test'], true));
    $unhealthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'paypal', [], false));

    expect($healthy->healthy)->toBeTrue()
        ->and($healthy->message)->toBe('PayPal credentials configured.')
        ->and($unhealthy->healthy)->toBeFalse()
        ->and($unhealthy->message)->toBe('PayPal credentials missing.');
});

it('handles intents, capture, void, refund, vaulting, disputes, and settlements', function () {
    $adapter = new PayPalPaymentAdapter();
    $account = new MerchantAccountContextDto('t1', 'paypal', ['client_id' => 'pp_test']);
    $amount = new MoneyAmount(2900, 'USD');

    $autoIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_1', returnUrl: 'https://example.test/return', captureImmediately: true));
    $manualIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_2', captureImmediately: false));
    $confirmed = $adapter->confirmIntent(new ConfirmPaymentIntentCommand($account, 'paypal_idem_1', 'idem_3'));
    $cancelled = $adapter->cancelIntent(new CancelPaymentIntentCommand($account, 'paypal_idem_1', 'idem_4'));
    $capturedWithAmount = $adapter->capture(new CapturePaymentCommand($account, 'paypal_idem_2', 'idem_5', $amount));
    $capturedDefault = $adapter->capture(new CapturePaymentCommand($account, 'paypal_idem_2', 'idem_6'));
    $voided = $adapter->voidAuthorization(new VoidPaymentCommand($account, 'paypal_idem_2', 'idem_7'));
    $refunded = $adapter->refund(new RefundPaymentCommand($account, 'paypal_idem_1', $amount, 'idem_8'));
    $setup = $adapter->beginSetup(new BeginPaymentMethodSetupCommand($account, 'cus_1', 'idem_9', 'https://example.test/setup'));
    $vaulted = $adapter->confirmSetup(new ConfirmPaymentMethodSetupCommand($account, $setup->setupReferenceId, 'idem_10'));
    $adapter->detachPaymentMethod(new DetachPaymentMethodCommand($account, $vaulted->providerMethodId));
    $dispute = $adapter->fetchDispute('dp_1', $account);
    $evidence = $adapter->submitEvidence(new SubmitDisputeEvidenceCommand($account, 'dp_1', ['note' => 'proof']));
    $settlements = $adapter->listSettlementTransactions(new SettlementQueryDto($account));

    expect($autoIntent->state)->toBe(PaymentLifecycleState::RequiresCustomerAction)
        ->and($manualIntent->state)->toBe(PaymentLifecycleState::Authorized)
        ->and($confirmed->state)->toBe(PaymentLifecycleState::Captured)
        ->and($cancelled->state)->toBe(PaymentLifecycleState::Cancelled)
        ->and($capturedWithAmount->capturedAmount->amountMinor)->toBe(2900)
        ->and($capturedDefault->capturedAmount->amountMinor)->toBe(0)
        ->and($voided->state)->toBe(PaymentLifecycleState::Voided)
        ->and($refunded->providerRefundId)->toBe('paypal_ref_idem_8')
        ->and($setup->setupReferenceId)->toBe('paypal_setup_idem_9')
        ->and($vaulted->methodType)->toBe('paypal')
        ->and($dispute->status)->toBe('open')
        ->and($evidence->status)->toBe('under_review')
        ->and($settlements)->toBe([]);
});

it('verifies and parses PayPal webhooks and rejects invalid signatures', function () {
    $adapter = new PayPalPaymentAdapter();
    $secret = 'pp_webhook_secret';
    $body = json_encode(['id' => 'evt_pp_1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource_id' => 'cap_1'], JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha256', $body, $secret);

    $parsed = $adapter->verifyAndParse(new RawWebhookPayloadDto('paypal', $body, ['PAYPAL-TRANSMISSION-SIG' => [$sig]], $secret));
    $parsedLower = $adapter->verifyAndParse(new RawWebhookPayloadDto('paypal', $body, ['paypal-transmission-sig' => $sig], $secret));
    $parsedFallback = $adapter->verifyAndParse(new RawWebhookPayloadDto('paypal', '', [], $secret));

    expect($parsed->providerEventId)->toBe('evt_pp_1')
        ->and($parsed->eventType)->toBe('PAYMENT.CAPTURE.COMPLETED')
        ->and($parsed->providerReference)->toBe('cap_1')
        ->and($parsedLower->providerEventId)->toBe('evt_pp_1')
        ->and($parsedFallback->eventType)->toBe('unknown');

    expect(fn () => $adapter->verifyAndParse(new RawWebhookPayloadDto('paypal', $body, ['PAYPAL-TRANSMISSION-SIG' => 'bad'], $secret)))
        ->toThrow(RuntimeException::class, 'Invalid PayPal webhook signature.');
});
