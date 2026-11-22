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
use Liberu\Payment\Square\SquarePaymentAdapter;
use Liberu\Payment\Square\SquarePaymentServiceProvider;
use Tests\ModulePackageTestCase;

uses(ModulePackageTestCase::class);

it('registers SquarePaymentAdapter as singleton and into PaymentGatewayRegistryContract on boot', function () {
    $adapter = app(SquarePaymentAdapter::class);
    expect($adapter)->toBe(app(SquarePaymentAdapter::class));

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
    (new SquarePaymentServiceProvider(app()))->boot();

    expect($registry->has('square'))->toBeTrue()
        ->and($registry->resolve('square'))->toBe($adapter);
});

it('exposes provider key, descriptor, and connection health', function () {
    $adapter = new SquarePaymentAdapter();
    $descriptor = $adapter->descriptor();

    expect($adapter->providerKey())->toBe('square')
        ->and($descriptor->providerKey)->toBe('square')
        ->and($descriptor->displayName)->toBe('Square')
        ->and($descriptor->supportsPosTerminal)->toBeTrue()
        ->and($descriptor->isMerchantOfRecord)->toBeFalse();

    $healthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'square', ['access_token' => 'sq0atp_test'], true));
    $unhealthy = $adapter->testConnection(new MerchantAccountContextDto('t1', 'square', [], false));

    expect($healthy->healthy)->toBeTrue()
        ->and($healthy->message)->toBe('Square credentials configured.')
        ->and($unhealthy->healthy)->toBeFalse()
        ->and($unhealthy->message)->toBe('Square credentials missing.');
});

it('handles intents, capture, void, refund, vaulting, disputes, settlements, and POS terminal payments', function () {
    $adapter = new SquarePaymentAdapter();
    $account = new MerchantAccountContextDto('t1', 'square', ['access_token' => 'sq0atp_test']);
    $amount = new MoneyAmount(3200, 'USD');

    $autoIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_1', returnUrl: 'https://example.test/return', captureImmediately: true));
    $manualIntent = $adapter->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_2', captureImmediately: false));
    $confirmed = $adapter->confirmIntent(new ConfirmPaymentIntentCommand($account, 'square_idem_1', 'idem_3'));
    $cancelled = $adapter->cancelIntent(new CancelPaymentIntentCommand($account, 'square_idem_1', 'idem_4'));
    $capturedWithAmount = $adapter->capture(new CapturePaymentCommand($account, 'square_idem_2', 'idem_5', $amount));
    $capturedDefault = $adapter->capture(new CapturePaymentCommand($account, 'square_idem_2', 'idem_6'));
    $voided = $adapter->voidAuthorization(new VoidPaymentCommand($account, 'square_idem_2', 'idem_7'));
    $refunded = $adapter->refund(new RefundPaymentCommand($account, 'square_idem_1', $amount, 'idem_8'));
    $setup = $adapter->beginSetup(new BeginPaymentMethodSetupCommand($account, 'cus_1', 'idem_9', 'https://example.test/setup'));
    $vaulted = $adapter->confirmSetup(new ConfirmPaymentMethodSetupCommand($account, $setup->setupReferenceId, 'idem_10'));
    $adapter->detachPaymentMethod(new DetachPaymentMethodCommand($account, $vaulted->providerMethodId));
    $dispute = $adapter->fetchDispute('dp_1', $account);
    $evidence = $adapter->submitEvidence(new SubmitDisputeEvidenceCommand($account, 'dp_1', ['note' => 'proof']));
    $settlements = $adapter->listSettlementTransactions(new SettlementQueryDto($account));
    $terminal = $adapter->createTerminalPayment(new CreateTerminalPaymentCommand($account, 'reader_1', $amount, 'idem_term_1'));

    expect($autoIntent->state)->toBe(PaymentLifecycleState::RequiresCustomerAction)
        ->and($manualIntent->state)->toBe(PaymentLifecycleState::Authorized)
        ->and($confirmed->state)->toBe(PaymentLifecycleState::Captured)
        ->and($cancelled->state)->toBe(PaymentLifecycleState::Cancelled)
        ->and($capturedWithAmount->capturedAmount->amountMinor)->toBe(3200)
        ->and($capturedDefault->capturedAmount->amountMinor)->toBe(0)
        ->and($voided->state)->toBe(PaymentLifecycleState::Voided)
        ->and($refunded->providerRefundId)->toBe('square_ref_idem_8')
        ->and($setup->setupReferenceId)->toBe('square_setup_idem_9')
        ->and($vaulted->methodType)->toBe('card')
        ->and($dispute->status)->toBe('open')
        ->and($evidence->status)->toBe('under_review')
        ->and($settlements)->toBe([])
        ->and($terminal->providerPaymentId)->toBe('square_term_idem_term_1')
        ->and($terminal->state)->toBe(PaymentLifecycleState::Processing);
});

it('verifies and parses Square webhooks and rejects invalid signatures', function () {
    $adapter = new SquarePaymentAdapter();
    $secret = 'sq_webhook_secret';
    $body = json_encode(['id' => 'evt_sq_1', 'type' => 'payment.updated', 'resource_id' => 'pay_1'], JSON_THROW_ON_ERROR);
    $sig = hash_hmac('sha256', $body, $secret);

    $parsed = $adapter->verifyAndParse(new RawWebhookPayloadDto('square', $body, ['x-square-hmacsha256-signature' => [$sig]], $secret));
    $parsedFallback = $adapter->verifyAndParse(new RawWebhookPayloadDto('square', '', [], $secret));

    expect($parsed->providerEventId)->toBe('evt_sq_1')
        ->and($parsed->eventType)->toBe('payment.updated')
        ->and($parsed->providerReference)->toBe('pay_1')
        ->and($parsedFallback->eventType)->toBe('unknown');

    expect(fn () => $adapter->verifyAndParse(new RawWebhookPayloadDto('square', $body, ['x-square-hmacsha256-signature' => 'bad'], $secret)))
        ->toThrow(RuntimeException::class, 'Invalid Square webhook signature.');
});
