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
use Liberu\Payment\Stripe\StripePaymentAdapter;
use Liberu\Payment\Stripe\StripePaymentServiceProvider;
use Stripe\ApiRequestor;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\ModulePackageTestCase;

uses(ModulePackageTestCase::class);

/**
 * Fake Stripe HTTP client that records requests and returns queued JSON responses.
 */
function installFakeStripeHttpClient(array $responses): object
{
    $fake = new class($responses) implements ClientInterface
    {
        /** @var list<array{method: string, url: string, headers: array<int, string>, params: array<string, mixed>}> */
        public array $requests = [];

        /** @var list<array{body: array<string, mixed>, status?: int}> */
        private array $queue;

        public function __construct(array $responses)
        {
            $this->queue = $responses;
        }

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = [
                'method' => strtolower((string) $method),
                'url' => (string) $absUrl,
                'headers' => $headers,
                'params' => $params,
            ];

            $next = array_shift($this->queue) ?? ['body' => [], 'status' => 200];

            return [
                json_encode($next['body'], JSON_THROW_ON_ERROR),
                $next['status'] ?? 200,
                ['Content-Type' => 'application/json'],
            ];
        }
    };

    ApiRequestor::setHttpClient($fake);

    return $fake;
}

function stripeAccount(array $credentials = ['secret_key' => 'sk_test_123'], bool $sandbox = true, ?string $apiVersion = null): MerchantAccountContextDto
{
    return new MerchantAccountContextDto(
        tenantId: 'tenant_1',
        providerKey: 'stripe',
        credentials: $credentials,
        sandbox: $sandbox,
        apiVersion: $apiVersion,
    );
}

function signStripePayload(string $body, string $secret, ?int $timestamp = null): string
{
    $ts = $timestamp ?? time();
    $sig = hash_hmac('sha256', "{$ts}.{$body}", $secret);

    return "t={$ts},v1={$sig}";
}

afterEach(function () {
    ApiRequestor::setHttpClient(CurlClient::instance());
});

it('registers StripePaymentAdapter as singleton and into PaymentGatewayRegistryContract on boot', function () {
    $adapter1 = app(StripePaymentAdapter::class);
    $adapter2 = app(StripePaymentAdapter::class);

    expect($adapter1)->toBe($adapter2);

    $registered = [];
    $registry = new class($registered) implements PaymentGatewayRegistryContract
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
    (new StripePaymentServiceProvider(app()))->boot();

    expect($registry->has('stripe'))->toBeTrue()
        ->and($registry->resolve('stripe'))->toBe($adapter1);
});

it('exposes provider key and capability descriptor', function () {
    $adapter = new StripePaymentAdapter();
    $descriptor = $adapter->descriptor();

    expect($adapter->providerKey())->toBe('stripe')
        ->and($descriptor->providerKey)->toBe('stripe')
        ->and($descriptor->displayName)->toBe('Stripe')
        ->and($descriptor->supportsAuthorizeOnly)->toBeTrue()
        ->and($descriptor->supportsPartialCapture)->toBeTrue()
        ->and($descriptor->supportsPartialRefund)->toBeTrue()
        ->and($descriptor->supportsVaulting)->toBeTrue()
        ->and($descriptor->supportsMandates)->toBeTrue()
        ->and($descriptor->supportsDisputes)->toBeTrue()
        ->and($descriptor->supportsSettlementReconciliation)->toBeTrue()
        ->and($descriptor->supportsPosTerminal)->toBeTrue()
        ->and($descriptor->isMerchantOfRecord)->toBeFalse();
});

it('tests connection using Stripe balance endpoint and config fallback', function () {
    config([
        'services.stripe.secret' => 'sk_test_from_config',
        'services.stripe.api_version' => '2026-02-25.clover',
    ]);

    $fake = installFakeStripeHttpClient([
        ['body' => ['object' => 'balance', 'livemode' => false]],
    ]);

    $adapter = new StripePaymentAdapter();
    $health = $adapter->testConnection(stripeAccount(credentials: [], sandbox: true));

    expect($health->providerKey)->toBe('stripe')
        ->and($health->healthy)->toBeTrue()
        ->and($health->message)->toBe('Stripe API connection verified.')
        ->and($health->details)->toBe(['livemode' => false])
        ->and($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]['url'])->toContain('/v1/balance');
});

it('creates payment intents with optional parameters and maps all lifecycle statuses', function (string $stripeStatus, PaymentLifecycleState $expectedState) {
    $fake = installFakeStripeHttpClient([
        [
            'body' => [
                'id' => 'pi_test_1',
                'object' => 'payment_intent',
                'status' => $stripeStatus,
                'amount' => 2500,
                'currency' => 'usd',
                'client_secret' => 'pi_test_1_secret_abc',
                'next_action' => [
                    'type' => 'redirect_to_url',
                    'redirect_to_url' => ['url' => 'https://stripe.test/3ds'],
                ],
            ],
        ],
    ]);

    $adapter = new StripePaymentAdapter();
    $result = $adapter->createIntent(new CreatePaymentIntentCommand(
        account: stripeAccount(apiVersion: '2026-02-25.clover'),
        amount: new MoneyAmount(2500, 'USD'),
        idempotencyKey: 'idem_create_1',
        paymentMethodToken: 'pm_card_visa',
        customerReference: 'cus_123',
        description: 'Invoice #100',
        returnUrl: 'https://merchant.test/return',
        captureImmediately: false,
        metadata: ['invoice_id' => '100'],
    ));

    expect($result->providerKey)->toBe('stripe')
        ->and($result->providerIntentId)->toBe('pi_test_1')
        ->and($result->state)->toBe($expectedState)
        ->and($result->amount->amountMinor)->toBe(2500)
        ->and($result->amount->currency)->toBe('USD')
        ->and($result->clientSecret)->toBe('pi_test_1_secret_abc')
        ->and($result->redirectUrl)->toBe('https://stripe.test/3ds')
        ->and($fake->requests[0]['params']['capture_method'])->toBe('manual')
        ->and($fake->requests[0]['params']['payment_method'])->toBe('pm_card_visa')
        ->and($fake->requests[0]['params']['confirm'])->toBe('true')
        ->and($fake->requests[0]['params']['return_url'])->toBe('https://merchant.test/return')
        ->and($fake->requests[0]['params']['customer'])->toBe('cus_123')
        ->and($fake->requests[0]['params']['description'])->toBe('Invoice #100');
})->with([
    'requires_payment_method' => ['requires_payment_method', PaymentLifecycleState::RequiresPaymentMethod],
    'requires_confirmation' => ['requires_confirmation', PaymentLifecycleState::RequiresCustomerAction],
    'requires_action' => ['requires_action', PaymentLifecycleState::RequiresCustomerAction],
    'processing' => ['processing', PaymentLifecycleState::Processing],
    'requires_capture' => ['requires_capture', PaymentLifecycleState::Authorized],
    'succeeded' => ['succeeded', PaymentLifecycleState::Captured],
    'canceled' => ['canceled', PaymentLifecycleState::Cancelled],
    'unknown default' => ['unexpected_state', PaymentLifecycleState::Processing],
]);

it('confirms and cancels payment intents', function () {
    $fake = installFakeStripeHttpClient([
        [
            'body' => [
                'id' => 'pi_confirm_1',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 3000,
                'currency' => 'gbp',
                'client_secret' => 'pi_confirm_1_secret',
            ],
        ],
        [
            'body' => [
                'id' => 'pi_confirm_1',
                'object' => 'payment_intent',
                'status' => 'canceled',
                'amount' => 3000,
                'currency' => 'gbp',
            ],
        ],
    ]);

    $adapter = new StripePaymentAdapter();
    $confirmed = $adapter->confirmIntent(new ConfirmPaymentIntentCommand(
        account: stripeAccount(),
        providerIntentId: 'pi_confirm_1',
        idempotencyKey: 'idem_confirm_1',
        paymentMethodToken: 'pm_123',
        payload: ['return_url' => 'https://merchant.test/cb'],
    ));
    $cancelled = $adapter->cancelIntent(new CancelPaymentIntentCommand(
        account: stripeAccount(),
        providerIntentId: 'pi_confirm_1',
        idempotencyKey: 'idem_cancel_1',
        reason: 'requested_by_customer',
    ));

    expect($confirmed->state)->toBe(PaymentLifecycleState::Captured)
        ->and($confirmed->amount->currency)->toBe('GBP')
        ->and($cancelled->state)->toBe(PaymentLifecycleState::Cancelled)
        ->and($fake->requests[0]['params']['payment_method'])->toBe('pm_123')
        ->and($fake->requests[1]['params']['cancellation_reason'])->toBe('requested_by_customer');
});

it('captures full and partial amounts, voids authorizations, and refunds payments', function () {
    installFakeStripeHttpClient([
        [
            'body' => [
                'id' => 'pi_cap_1',
                'object' => 'payment_intent',
                'amount' => 5000,
                'amount_received' => 5000,
                'currency' => 'usd',
                'latest_charge' => 'ch_full_1',
            ],
        ],
        [
            'body' => [
                'id' => 'pi_cap_2',
                'object' => 'payment_intent',
                'amount' => 5000,
                'amount_received' => 2000,
                'currency' => 'usd',
                'latest_charge' => 'ch_part_1',
            ],
        ],
        [
            'body' => [
                'id' => 'pi_void_1',
                'object' => 'payment_intent',
                'status' => 'canceled',
            ],
        ],
        [
            'body' => [
                'id' => 're_1',
                'object' => 'refund',
                'amount' => 1500,
                'currency' => 'usd',
                'status' => 'succeeded',
            ],
        ],
    ]);

    $adapter = new StripePaymentAdapter();
    $full = $adapter->capture(new CapturePaymentCommand(stripeAccount(), 'pi_cap_1', 'idem_cap_1'));
    $partial = $adapter->capture(new CapturePaymentCommand(stripeAccount(), 'pi_cap_2', 'idem_cap_2', new MoneyAmount(2000, 'USD')));
    $voided = $adapter->voidAuthorization(new VoidPaymentCommand(stripeAccount(), 'pi_void_1', 'idem_void_1'));
    $refund = $adapter->refund(new RefundPaymentCommand(stripeAccount(), 'pi_cap_1', new MoneyAmount(1500, 'USD'), 'idem_ref_1'));

    expect($full->state)->toBe(PaymentLifecycleState::Captured)
        ->and($full->providerCaptureId)->toBe('ch_full_1')
        ->and($full->capturedAmount->amountMinor)->toBe(5000)
        ->and($partial->state)->toBe(PaymentLifecycleState::PartiallyCaptured)
        ->and($partial->capturedAmount->amountMinor)->toBe(2000)
        ->and($voided->state)->toBe(PaymentLifecycleState::Voided)
        ->and($refund->providerRefundId)->toBe('re_1')
        ->and($refund->state)->toBe(PaymentLifecycleState::Refunded)
        ->and($refund->refundedAmount->amountMinor)->toBe(1500);
});

it('begins setup, confirms setup, and detaches vaulted payment methods', function () {
    $fake = installFakeStripeHttpClient([
        [
            'body' => [
                'id' => 'seti_1',
                'object' => 'setup_intent',
                'client_secret' => 'seti_1_secret',
            ],
        ],
        [
            'body' => [
                'id' => 'seti_1',
                'object' => 'setup_intent',
                'payment_method' => 'pm_saved_1',
                'customer' => 'cus_1',
                'mandate' => 'mandate_1',
            ],
        ],
        [
            'body' => [
                'id' => 'pm_saved_1',
                'object' => 'payment_method',
            ],
        ],
    ]);

    $adapter = new StripePaymentAdapter();
    $setup = $adapter->beginSetup(new BeginPaymentMethodSetupCommand(stripeAccount(), 'cus_1', 'idem_setup_1', null, ['source' => 'billing']));
    $vaulted = $adapter->confirmSetup(new ConfirmPaymentMethodSetupCommand(stripeAccount(), 'seti_1', 'idem_setup_2'));
    $adapter->detachPaymentMethod(new DetachPaymentMethodCommand(stripeAccount(), 'pm_saved_1'));

    expect($setup->setupReferenceId)->toBe('seti_1')
        ->and($setup->clientSecret)->toBe('seti_1_secret')
        ->and($vaulted->providerMethodId)->toBe('pm_saved_1')
        ->and($vaulted->customerReference)->toBe('cus_1')
        ->and($vaulted->mandateReference)->toBe('mandate_1')
        ->and($fake->requests[2]['url'])->toContain('/v1/payment_methods/pm_saved_1/detach');
});

it('verifies and parses Stripe webhooks for all mapped event types and array headers', function (string $eventType, ?PaymentLifecycleState $expectedState) {
    $secret = 'whsec_test_secret';
    $rawBody = json_encode([
        'id' => 'evt_123',
        'object' => 'event',
        'type' => $eventType,
        'data' => [
            'object' => ['id' => 'pi_999'],
        ],
    ], JSON_THROW_ON_ERROR);

    $adapter = new StripePaymentAdapter();
    $event = $adapter->verifyAndParse(new RawWebhookPayloadDto(
        providerKey: 'stripe',
        rawBody: $rawBody,
        headers: ['Stripe-Signature' => [signStripePayload($rawBody, $secret)]],
        signingSecret: $secret,
    ));

    expect($event->providerKey)->toBe('stripe')
        ->and($event->providerEventId)->toBe('evt_123')
        ->and($event->eventType)->toBe($eventType)
        ->and($event->providerReference)->toBe('pi_999')
        ->and($event->targetState)->toBe($expectedState);
})->with([
    'payment_intent.succeeded' => ['payment_intent.succeeded', PaymentLifecycleState::Captured],
    'payment_intent.payment_failed' => ['payment_intent.payment_failed', PaymentLifecycleState::Failed],
    'payment_intent.canceled' => ['payment_intent.canceled', PaymentLifecycleState::Cancelled],
    'charge.refunded' => ['charge.refunded', PaymentLifecycleState::Refunded],
    'charge.dispute.created' => ['charge.dispute.created', PaymentLifecycleState::Disputed],
    'unmapped event' => ['customer.created', null],
]);

it('rejects Stripe webhooks with invalid signatures', function () {
    $adapter = new StripePaymentAdapter();

    expect(fn () => $adapter->verifyAndParse(new RawWebhookPayloadDto(
        providerKey: 'stripe',
        rawBody: '{"id":"evt_bad"}',
        headers: ['stripe-signature' => 't=123,v1=invalid'],
        signingSecret: 'whsec_test_secret',
    )))->toThrow(SignatureVerificationException::class);
});

it('fetches disputes, submits dispute evidence, lists settlement transactions, and creates terminal payments', function () {
    $fake = installFakeStripeHttpClient([
        [
            'body' => [
                'id' => 'dp_1',
                'object' => 'dispute',
                'payment_intent' => 'pi_disp_1',
                'status' => 'needs_response',
                'reason' => 'fraudulent',
                'amount' => 4200,
                'currency' => 'eur',
            ],
        ],
        [
            'body' => [
                'id' => 'dp_1',
                'object' => 'dispute',
                'charge' => 'ch_disp_1',
                'status' => 'under_review',
                'reason' => 'fraudulent',
                'amount' => 4200,
                'currency' => 'eur',
            ],
        ],
        [
            'body' => [
                'object' => 'list',
                'has_more' => false,
                'data' => [
                    [
                        'id' => 'txn_1',
                        'object' => 'balance_transaction',
                        'source' => 'ch_1',
                        'amount' => 1000,
                        'fee' => 59,
                        'net' => 941,
                        'currency' => 'usd',
                        'type' => 'charge',
                        'created' => 1700000000,
                    ],
                ],
            ],
        ],
        [
            'body' => [
                'id' => 'pi_term_1',
                'object' => 'payment_intent',
                'status' => 'requires_payment_method',
                'amount' => 1200,
                'currency' => 'usd',
            ],
        ],
        [
            'body' => [
                'id' => 'tmr_1',
                'object' => 'terminal.reader',
            ],
        ],
    ]);

    $adapter = new StripePaymentAdapter();
    $dispute = $adapter->fetchDispute('dp_1', stripeAccount());
    $updated = $adapter->submitEvidence(new SubmitDisputeEvidenceCommand(stripeAccount(), 'dp_1', ['customer_name' => 'Alex'], true));
    $settlements = iterator_to_array($adapter->listSettlementTransactions(new SettlementQueryDto(stripeAccount(), payoutId: 'po_1', limit: 25)));
    $terminal = $adapter->createTerminalPayment(new CreateTerminalPaymentCommand(stripeAccount(), 'tmr_1', new MoneyAmount(1200, 'USD'), 'idem_term_1', ['register' => 'A1']));

    expect($dispute->providerDisputeId)->toBe('dp_1')
        ->and($dispute->providerPaymentReference)->toBe('pi_disp_1')
        ->and($dispute->amount->currency)->toBe('EUR')
        ->and($updated->providerPaymentReference)->toBe('ch_disp_1')
        ->and($updated->status)->toBe('under_review')
        ->and($settlements)->toHaveCount(1)
        ->and($settlements[0]->settlementTransactionId)->toBe('txn_1')
        ->and($settlements[0]->netAmount->amountMinor)->toBe(941)
        ->and($terminal->providerPaymentId)->toBe('pi_term_1')
        ->and($terminal->readerId)->toBe('tmr_1')
        ->and($terminal->state)->toBe(PaymentLifecycleState::RequiresPaymentMethod)
        ->and($fake->requests[2]['params']['payout'])->toBe('po_1')
        ->and($fake->requests[4]['url'])->toContain('/v1/terminal/readers/tmr_1/process_payment_intent');
});
