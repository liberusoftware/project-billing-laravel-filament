<?php

use Liberu\Billing\Payments\Contracts\GatewayDriver;
use Liberu\Billing\Payments\Models\Payment;
use Liberu\Billing\Payments\Services\GatewayManager;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;
use Liberu\Payment\Paddle\PaddlePaymentAdapter;

it('captures a Paddle payment through the provider-neutral payment contract', function (): void {
    $adapter = app(PaddlePaymentAdapter::class);
    $account = new MerchantAccountContextDto(
        tenantId: 'team-1',
        providerKey: 'paddle',
        credentials: ['token' => 'pdl_test_token'],
        sandbox: true,
    );

    $intent = $adapter->createIntent(new CreatePaymentIntentCommand(
        account: $account,
        amount: new MoneyAmount(1000, 'USD'),
        idempotencyKey: 'idem_create_123',
        returnUrl: 'https://billing.test/return',
    ));

    $capture = $adapter->capture(new CapturePaymentCommand(
        account: $account,
        providerIntentId: $intent->providerIntentId,
        idempotencyKey: 'idem_cap_123',
        amount: new MoneyAmount(1000, 'USD'),
    ));

    expect($intent->providerKey)->toBe('paddle')
        ->and($intent->providerIntentId)->toBe('paddle_idem_create_123')
        ->and($intent->state)->toBe(PaymentLifecycleState::RequiresCustomerAction)
        ->and($capture->providerCaptureId)->toBe('paddle_cap_idem_cap_123')
        ->and($capture->state)->toBe(PaymentLifecycleState::Captured)
        ->and($capture->capturedAmount->amountMinor)->toBe(1000);
});

it('supports full and partial Paddle refunds', function (): void {
    $adapter = app(PaddlePaymentAdapter::class);
    $account = new MerchantAccountContextDto(
        tenantId: 'team-1',
        providerKey: 'paddle',
        credentials: ['token' => 'pdl_test_token'],
    );

    $full = $adapter->refund(new RefundPaymentCommand(
        account: $account,
        providerReference: 'txn_123',
        amount: new MoneyAmount(1000, 'USD'),
        idempotencyKey: 'full_1',
    ));

    $partial = $adapter->refund(new RefundPaymentCommand(
        account: $account,
        providerReference: 'txn_123',
        amount: new MoneyAmount(400, 'USD'),
        idempotencyKey: 'partial_1',
    ));

    expect($full->providerRefundId)->toBe('paddle_ref_full_1')
        ->and($full->state)->toBe(PaymentLifecycleState::Refunded)
        ->and($full->refundedAmount->amountMinor)->toBe(1000)
        ->and($partial->providerRefundId)->toBe('paddle_ref_partial_1')
        ->and($partial->refundedAmount->amountMinor)->toBe(400);
});

it('reports connection health based on configured Paddle credentials', function (): void {
    $adapter = app(PaddlePaymentAdapter::class);

    $healthy = $adapter->testConnection(new MerchantAccountContextDto('team-1', 'paddle', ['token' => 'pdl_test']));
    $unhealthy = $adapter->testConnection(new MerchantAccountContextDto('team-1', 'paddle', []));

    expect($healthy->healthy)->toBeTrue()
        ->and($unhealthy->healthy)->toBeFalse();
});

it('verifies Paddle webhook signatures and rejects invalid ones', function (): void {
    $adapter = app(PaddlePaymentAdapter::class);
    $body = json_encode(['id' => 'evt_123', 'event_type' => 'transaction.completed', 'resource_id' => 'txn_123'], JSON_THROW_ON_ERROR);
    $secret = 'whsec_test_paddle';
    $validSig = hash_hmac('sha256', $body, $secret);

    $event = $adapter->verifyAndParse(new RawWebhookPayloadDto(
        providerKey: 'paddle',
        rawBody: $body,
        headers: ['Paddle-Signature' => $validSig],
        signingSecret: $secret,
    ));

    expect($event->providerEventId)->toBe('evt_123')
        ->and($event->eventType)->toBe('transaction.completed')
        ->and($event->providerReference)->toBe('txn_123');

    expect(fn () => $adapter->verifyAndParse(new RawWebhookPayloadDto(
        providerKey: 'paddle',
        rawBody: $body,
        headers: ['Paddle-Signature' => 'invalid_signature'],
        signingSecret: $secret,
    )))->toThrow(RuntimeException::class, 'Invalid Paddle Billing webhook signature.');
});

it('resolves the Paddle gateway without case-sensitive aliases', function (): void {
    $manager = new GatewayManager();
    $driver = new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            return [];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            return [];
        }
    };
    $manager->register(' Paddle ', $driver);

    expect($manager->driver('PADDLE'))->toBe($driver);
});
