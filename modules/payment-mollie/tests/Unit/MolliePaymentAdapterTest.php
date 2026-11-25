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
use Liberu\Payment\Mollie\MolliePaymentAdapter;
use Liberu\Payment\Mollie\MolliePaymentServiceProvider;
use Tests\ModulePackageTestCase;

uses(ModulePackageTestCase::class);

it('registers MolliePaymentAdapter as singleton and into PaymentGatewayRegistryContract on boot', function () {
    $adapter = app(MolliePaymentAdapter::class);
    expect($adapter)->toBe(app(MolliePaymentAdapter::class));

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
    (new MolliePaymentServiceProvider(app()))->boot();

    expect($registry->has('mollie'))->toBeTrue()
        ->and($registry->resolve('mollie'))->toBe($adapter);
});

it('exposes provider key, descriptor, and connection health', function () {
    $adapter = new MolliePaymentAdapter();
    $descriptor = $adapter->descriptor();

    expect($adapter->providerKey())->toBe('mollie')
        ->and($descriptor->providerKey)->toBe('mollie')
        ->and($descriptor->displayName)->toBe('Mollie')
        ->and($descriptor->supportsMandates)->toBeTrue()
        ->and($descriptor->supportsPosTerminal)->toBeFalse();

    $healthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'mollie', ['api_key' => 'test_mollie'], true));
    $unhealthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'mollie', [], false));

    expect($healthy->healthy)->toBeTrue()
        ->and($healthy->message)->toBe('Mollie credentials configured.')
        ->and($unhealthy->healthy)->toBeFalse()
        ->and($unhealthy->message)->toBe('Mollie credentials missing.');
});

it('handles intents, capture, void, refund, vaulting, disputes, and settlements', function () {
    $adapter = new MolliePaymentAdapter();
    $account = new MerchantAccountContextDto('t1', 'mollie', ['api_key' => 'test_mollie']);
    $amount = new MoneyAmount(1950, 'EUR');

    $autoIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_1', returnUrl: 'https://example.test/return', captureImmediately: true));
    $manualIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_2', captureImmediately: false));
    $confirmed = $adapter->confirmIntent(new ConfirmPaymentIntentCommand($account, 'mollie_idem_1', 'idem_3'));
    $cancelled = $adapter->cancelIntent(new CancelPaymentIntentCommand($account, 'mollie_idem_1', 'idem_4'));
    $capturedWithAmount = $adapter->capture(new CapturePaymentCommand($account, 'mollie_idem_2', 'idem_5', $amount));
    $capturedDefault = $adapter->capture(new CapturePaymentCommand($account, 'mollie_idem_2', 'idem_6'));
    $voided = $adapter->voidAuthorization(new VoidPaymentCommand($account, 'mollie_idem_2', 'idem_7'));
    $refunded = $adapter->refund(new RefundPaymentCommand($account, 'mollie_idem_1', $amount, 'idem_8'));
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
        ->and($capturedWithAmount->capturedAmount->amountMinor)->toBe(1950)
        ->and($capturedDefault->capturedAmount->amountMinor)->toBe(0)
        ->and($voided->state)->toBe(PaymentLifecycleState::Voided)
        ->and($refunded->providerRefundId)->toBe('mollie_ref_idem_8')
        ->and($setup->setupReferenceId)->toBe('mollie_setup_idem_9')
        ->and($vaulted->methodType)->toBe('ideal')
        ->and($dispute->status)->toBe('open')
        ->and($evidence->status)->toBe('under_review')
        ->and($settlements)->toBe([]);
});

it('verifies and parses Mollie webhooks and rejects invalid signatures', function () {
    $adapter = new MolliePaymentAdapter();
    $secret = 'mollie_webhook_secret';
    $body = json_encode(['id' => 'evt_mollie_1', 'event_type' => 'payment.paid', 'resource_id' => 'tr_1'], JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha256', $body, $secret);

    $parsed = $adapter->verifyAndParse(new RawWebhookPayloadDto('mollie', $body, ['X-Mollie-Signature' => [$sig]], $secret));
    $parsedLower = $adapter->verifyAndParse(new RawWebhookPayloadDto('mollie', $body, ['x-mollie-signature' => $sig], $secret));
    $parsedFallback = $adapter->verifyAndParse(new RawWebhookPayloadDto('mollie', '', [], $secret));

    expect($parsed->providerEventId)->toBe('evt_mollie_1')
        ->and($parsed->eventType)->toBe('payment.paid')
        ->and($parsed->providerReference)->toBe('tr_1')
        ->and($parsedLower->providerEventId)->toBe('evt_mollie_1')
        ->and($parsedFallback->eventType)->toBe('unknown');

    expect(fn () => $adapter->verifyAndParse(new RawWebhookPayloadDto('mollie', $body, ['X-Mollie-Signature' => 'bad'], $secret)))
        ->toThrow(RuntimeException::class, 'Invalid Mollie webhook signature.');
});
