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
use Liberu\Payment\Paddle\PaddlePaymentAdapter;
use Liberu\Payment\Paddle\PaddlePaymentServiceProvider;
use Tests\ModulePackageTestCase;

uses(ModulePackageTestCase::class);

it('registers PaddlePaymentAdapter as singleton and into PaymentGatewayRegistryContract on boot', function () {
    $adapter = app(PaddlePaymentAdapter::class);
    expect($adapter)->toBe(app(PaddlePaymentAdapter::class));

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
    (new PaddlePaymentServiceProvider(app()))->boot();

    expect($registry->has('paddle'))->toBeTrue()
        ->and($registry->resolve('paddle'))->toBe($adapter);
});

it('exposes provider key, descriptor, and connection health', function () {
    $adapter = new PaddlePaymentAdapter();
    $descriptor = $adapter->descriptor();

    expect($adapter->providerKey())->toBe('paddle')
        ->and($descriptor->providerKey)->toBe('paddle')
        ->and($descriptor->displayName)->toBe('Paddle Billing')
        ->and($descriptor->isMerchantOfRecord)->toBeTrue()
        ->and($descriptor->supportsPosTerminal)->toBeFalse();

    $healthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'paddle', ['api_key' => 'pdl_test'], true));
    $unhealthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'paddle', [], false));

    expect($healthy->healthy)->toBeTrue()
        ->and($healthy->message)->toBe('Paddle Billing credentials configured.')
        ->and($healthy->details)->toBe(['sandbox' => true])
        ->and($unhealthy->healthy)->toBeFalse()
        ->and($unhealthy->message)->toBe('Paddle Billing credentials missing.');
});

it('handles intents, capture, void, refund, vaulting, disputes, and settlements', function () {
    $adapter = new PaddlePaymentAdapter();
    $account = new MerchantAccountContextDto('t1', 'paddle', ['api_key' => 'pdl_test']);
    $amount = new MoneyAmount(4900, 'USD');

    $autoIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_1', returnUrl: 'https://example.test/return', captureImmediately: true));
    $manualIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_2', captureImmediately: false));
    $confirmed = $adapter->confirmIntent(new ConfirmPaymentIntentCommand($account, 'paddle_idem_1', 'idem_3'));
    $cancelled = $adapter->cancelIntent(new CancelPaymentIntentCommand($account, 'paddle_idem_1', 'idem_4'));
    $capturedWithAmount = $adapter->capture(new CapturePaymentCommand($account, 'paddle_idem_2', 'idem_5', $amount));
    $capturedDefault = $adapter->capture(new CapturePaymentCommand($account, 'paddle_idem_2', 'idem_6'));
    $voided = $adapter->voidAuthorization(new VoidPaymentCommand($account, 'paddle_idem_2', 'idem_7'));
    $refunded = $adapter->refund(new RefundPaymentCommand($account, 'paddle_idem_1', $amount, 'idem_8'));
    $setup = $adapter->beginSetup(new BeginPaymentMethodSetupCommand($account, 'cus_1', 'idem_9', 'https://example.test/setup'));
    $vaulted = $adapter->confirmSetup(new ConfirmPaymentMethodSetupCommand($account, $setup->setupReferenceId, 'idem_10'));
    $adapter->detachPaymentMethod(new DetachPaymentMethodCommand($account, $vaulted->providerMethodId));
    $dispute = $adapter->fetchDispute('dp_1', $account);
    $evidence = $adapter->submitEvidence(new SubmitDisputeEvidenceCommand($account, 'dp_1', ['note' => 'proof']));
    $settlements = $adapter->listSettlementTransactions(new SettlementQueryDto($account));

    expect($autoIntent->state)->toBe(PaymentLifecycleState::RequiresCustomerAction)
        ->and($autoIntent->providerIntentId)->toBe('paddle_idem_1')
        ->and($manualIntent->state)->toBe(PaymentLifecycleState::Authorized)
        ->and($confirmed->state)->toBe(PaymentLifecycleState::Captured)
        ->and($cancelled->state)->toBe(PaymentLifecycleState::Cancelled)
        ->and($capturedWithAmount->capturedAmount->amountMinor)->toBe(4900)
        ->and($capturedDefault->capturedAmount->amountMinor)->toBe(0)
        ->and($voided->state)->toBe(PaymentLifecycleState::Voided)
        ->and($refunded->providerRefundId)->toBe('paddle_ref_idem_8')
        ->and($setup->setupReferenceId)->toBe('paddle_setup_idem_9')
        ->and($vaulted->methodType)->toBe('card')
        ->and($dispute->status)->toBe('open')
        ->and($evidence->status)->toBe('under_review')
        ->and($settlements)->toBe([]);
});

it('verifies and parses Paddle webhooks and rejects invalid signatures', function () {
    $adapter = new PaddlePaymentAdapter();
    $secret = 'pdl_ntfset_secret';
    $body = json_encode(['id' => 'evt_1', 'event_type' => 'transaction.completed', 'resource_id' => 'txn_1'], JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha256', $body, $secret);

    $parsed = $adapter->verifyAndParse(new RawWebhookPayloadDto('paddle', $body, ['Paddle-Signature' => [$sig]], $secret));
    $parsedCompound = $adapter->verifyAndParse(new RawWebhookPayloadDto('paddle', $body, ['paddle-signature' => "ts=1;h1={$sig}"], $secret));
    $parsedFallback = $adapter->verifyAndParse(new RawWebhookPayloadDto('paddle', 'not-json', [], $secret));

    expect($parsed->providerEventId)->toBe('evt_1')
        ->and($parsed->eventType)->toBe('transaction.completed')
        ->and($parsed->providerReference)->toBe('txn_1')
        ->and($parsedCompound->providerEventId)->toBe('evt_1')
        ->and($parsedFallback->providerEventId)->toBe(sha1('not-json'))
        ->and($parsedFallback->eventType)->toBe('unknown')
        ->and($parsedFallback->providerReference)->toBeNull();

    expect(fn () => $adapter->verifyAndParse(new RawWebhookPayloadDto('paddle', $body, ['Paddle-Signature' => 'invalid'], $secret)))
        ->toThrow(RuntimeException::class, 'Invalid Paddle Billing webhook signature.');
});
