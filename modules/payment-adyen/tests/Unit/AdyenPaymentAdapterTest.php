<?php

declare(strict_types=1);

use Liberu\Payment\Adyen\AdyenPaymentAdapter;
use Liberu\Payment\Adyen\AdyenPaymentServiceProvider;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Contracts\DTOs\BeginPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\CancelPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CreateTerminalPaymentCommand;
use Liberu\Payment\Contracts\DTOs\DetachPaymentMethodCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\DTOs\SettlementQueryDto;
use Liberu\Payment\Contracts\DTOs\SubmitDisputeEvidenceCommand;
use Liberu\Payment\Contracts\DTOs\VoidPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;
use Tests\ModulePackageTestCase;

uses(ModulePackageTestCase::class);

it('registers AdyenPaymentAdapter as singleton and into PaymentGatewayRegistryContract on boot', function () {
    $adapter = app(AdyenPaymentAdapter::class);
    expect($adapter)->toBe(app(AdyenPaymentAdapter::class));

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
    (new AdyenPaymentServiceProvider(app()))->boot();

    expect($registry->has('adyen'))->toBeTrue()
        ->and($registry->resolve('adyen'))->toBe($adapter);
});

it('exposes provider key, descriptor, and connection health', function () {
    $adapter = new AdyenPaymentAdapter();
    $descriptor = $adapter->descriptor();

    expect($adapter->providerKey())->toBe('adyen')
        ->and($descriptor->providerKey)->toBe('adyen')
        ->and($descriptor->displayName)->toBe('Adyen')
        ->and($descriptor->supportsMandates)->toBeTrue()
        ->and($descriptor->supportsPosTerminal)->toBeTrue();

    $healthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'adyen', ['api_key' => 'AQE_test'], true));
    $unhealthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'adyen', [], false));

    expect($healthy->healthy)->toBeTrue()
        ->and($healthy->message)->toBe('Adyen credentials configured.')
        ->and($unhealthy->healthy)->toBeFalse()
        ->and($unhealthy->message)->toBe('Adyen credentials missing.');
});

it('handles intents, capture, void, refund, vaulting, disputes, settlements, and POS terminal payments', function () {
    $adapter = new AdyenPaymentAdapter();
    $account = new MerchantAccountContextDto('t1', 'adyen', ['api_key' => 'AQE_test']);
    $amount = new MoneyAmount(7500, 'EUR');

    $autoIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_1', returnUrl: 'https://example.test/return', captureImmediately: true));
    $manualIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_2', captureImmediately: false));
    $confirmed = $adapter->confirmIntent(new ConfirmPaymentIntentCommand($account, 'adyen_idem_1', 'idem_3'));
    $cancelled = $adapter->cancelIntent(new CancelPaymentIntentCommand($account, 'adyen_idem_1', 'idem_4'));
    $capturedWithAmount = $adapter->capture(new CapturePaymentCommand($account, 'adyen_idem_2', 'idem_5', $amount));
    $capturedDefault = $adapter->capture(new CapturePaymentCommand($account, 'adyen_idem_2', 'idem_6'));
    $voided = $adapter->voidAuthorization(new VoidPaymentCommand($account, 'adyen_idem_2', 'idem_7'));
    $refunded = $adapter->refund(new RefundPaymentCommand($account, 'adyen_idem_1', $amount, 'idem_8'));
    $setup = $adapter->beginSetup(new BeginPaymentMethodSetupCommand($account, 'cus_1', 'idem_9', 'https://example.test/setup'));
    $vaulted = $adapter->confirmSetup(new ConfirmPaymentMethodSetupCommand($account, $setup->setupReferenceId, 'idem_10'));
    $adapter->detachPaymentMethod(new DetachPaymentMethodCommand($account, $vaulted->providerMethodId));
    $dispute = $adapter->fetchDispute('dp_1', $account);
    $evidence = $adapter->submitEvidence(new SubmitDisputeEvidenceCommand($account, 'dp_1', ['note' => 'proof']));
    $settlements = $adapter->listSettlementTransactions(new SettlementQueryDto($account));
    $terminal = $adapter->createTerminalPayment(new CreateTerminalPaymentCommand($account, 'poi_1', $amount, 'idem_term_1'));

    expect($autoIntent->state)->toBe(PaymentLifecycleState::RequiresCustomerAction)
        ->and($manualIntent->state)->toBe(PaymentLifecycleState::Authorized)
        ->and($confirmed->state)->toBe(PaymentLifecycleState::Captured)
        ->and($cancelled->state)->toBe(PaymentLifecycleState::Cancelled)
        ->and($capturedWithAmount->capturedAmount->amountMinor)->toBe(7500)
        ->and($capturedDefault->capturedAmount->amountMinor)->toBe(0)
        ->and($voided->state)->toBe(PaymentLifecycleState::Voided)
        ->and($refunded->providerRefundId)->toBe('adyen_ref_idem_8')
        ->and($setup->setupReferenceId)->toBe('adyen_setup_idem_9')
        ->and($vaulted->methodType)->toBe('card')
        ->and($dispute->status)->toBe('open')
        ->and($evidence->status)->toBe('under_review')
        ->and($settlements)->toBe([])
        ->and($terminal->providerPaymentId)->toBe('adyen_term_idem_term_1')
        ->and($terminal->state)->toBe(PaymentLifecycleState::Processing);
});

it('verifies and parses Adyen webhooks and rejects invalid signatures', function () {
    $adapter = new AdyenPaymentAdapter();
    $secret = 'adyen_hmac_secret';
    $body = json_encode(['id' => 'evt_adyen_1', 'event_type' => 'AUTHORISATION', 'resource_id' => 'psp_1'], JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha256', $body, $secret);

    $parsed = $adapter->verifyAndParse(new RawWebhookPayloadDto('adyen', $body, ['X-Adyen-Hmac-Signature' => [$sig]], $secret));
    $parsedLower = $adapter->verifyAndParse(new RawWebhookPayloadDto('adyen', $body, ['x-adyen-hmac-signature' => $sig], $secret));
    $parsedFallback = $adapter->verifyAndParse(new RawWebhookPayloadDto('adyen', '', [], $secret));

    expect($parsed->providerEventId)->toBe('evt_adyen_1')
        ->and($parsed->eventType)->toBe('AUTHORISATION')
        ->and($parsed->providerReference)->toBe('psp_1')
        ->and($parsedLower->providerEventId)->toBe('evt_adyen_1')
        ->and($parsedFallback->eventType)->toBe('unknown');

    expect(fn () => $adapter->verifyAndParse(new RawWebhookPayloadDto('adyen', $body, ['X-Adyen-Hmac-Signature' => 'bad'], $secret)))
        ->toThrow(RuntimeException::class, 'Invalid Adyen webhook signature.');
});
