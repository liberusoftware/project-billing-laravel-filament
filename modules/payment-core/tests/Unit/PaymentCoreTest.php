<?php

declare(strict_types=1);

use Liberu\Payment\Contracts\Contracts\MerchantWebhookVerifierContract;
use Liberu\Payment\Contracts\Contracts\OutboundPaymentAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentCaptureAndRefundContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayAdapterContract;
use Liberu\Payment\Contracts\Contracts\PaymentGatewayRegistryContract;
use Liberu\Payment\Contracts\Contracts\PaymentIntentServiceContract;
use Liberu\Payment\Contracts\DTOs\CancelPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\CapturePaymentCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\ConnectionHealthDto;
use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MerchantWebhookEventDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\PaymentCapabilityDescriptorDto;
use Liberu\Payment\Contracts\DTOs\PaymentCaptureResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentIntentResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentRefundResultDto;
use Liberu\Payment\Contracts\DTOs\PaymentVoidResultDto;
use Liberu\Payment\Contracts\DTOs\RawWebhookPayloadDto;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\DTOs\VoidPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;
use Liberu\Payment\Core\Services\PaymentGatewayRegistry;
use Liberu\Payment\Core\Services\PaymentOrchestrator;
use Tests\ModulePackageTestCase;

uses(ModulePackageTestCase::class);

function makeMinimalAdapter(string $key): PaymentGatewayAdapterContract
{
    return new readonly class($key) implements PaymentGatewayAdapterContract
    {
        public function __construct(private string $key) {}

        public function providerKey(): string
        {
            return $this->key;
        }

        public function descriptor(): PaymentCapabilityDescriptorDto
        {
            return new PaymentCapabilityDescriptorDto($this->key, ucfirst($this->key));
        }

        public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
        {
            return new ConnectionHealthDto($this->key, true, 'ok');
        }
    };
}

function makeFullAdapter(string $key): PaymentGatewayAdapterContract
{
    return new readonly class($key) implements MerchantWebhookVerifierContract, OutboundPaymentAdapterContract, PaymentCaptureAndRefundContract, PaymentGatewayAdapterContract, PaymentIntentServiceContract
    {
        public function __construct(private string $key) {}

        public function providerKey(): string
        {
            return $this->key;
        }

        public function descriptor(): PaymentCapabilityDescriptorDto
        {
            return new PaymentCapabilityDescriptorDto($this->key, ucfirst($this->key));
        }

        public function testConnection(MerchantAccountContextDto $account): ConnectionHealthDto
        {
            return new ConnectionHealthDto($this->key, true, 'ok');
        }

        public function createIntent(CreatePaymentIntentCommand $command): PaymentIntentResultDto
        {
            return new PaymentIntentResultDto($this->key, 'pi_'.$command->idempotencyKey, PaymentLifecycleState::Authorized, $command->amount);
        }

        public function confirmIntent(ConfirmPaymentIntentCommand $command): PaymentIntentResultDto
        {
            return new PaymentIntentResultDto($this->key, $command->providerIntentId, PaymentLifecycleState::Captured, new MoneyAmount(0, 'USD'));
        }

        public function cancelIntent(CancelPaymentIntentCommand $command): PaymentIntentResultDto
        {
            return new PaymentIntentResultDto($this->key, $command->providerIntentId, PaymentLifecycleState::Cancelled, new MoneyAmount(0, 'USD'));
        }

        public function capture(CapturePaymentCommand $command): PaymentCaptureResultDto
        {
            return new PaymentCaptureResultDto($this->key, $command->providerIntentId, 'cap_1', PaymentLifecycleState::Captured, $command->amount ?? new MoneyAmount(0, 'USD'));
        }

        public function voidAuthorization(VoidPaymentCommand $command): PaymentVoidResultDto
        {
            return new PaymentVoidResultDto($this->key, $command->providerIntentId, PaymentLifecycleState::Voided);
        }

        public function refund(RefundPaymentCommand $command): PaymentRefundResultDto
        {
            return new PaymentRefundResultDto($this->key, $command->providerReference, 'ref_1', PaymentLifecycleState::Refunded, $command->amount);
        }

        public function verifyAndParse(RawWebhookPayloadDto $payload): MerchantWebhookEventDto
        {
            return new MerchantWebhookEventDto($this->key, 'evt_1', 'payment.captured', 'pi_1', PaymentLifecycleState::Captured);
        }

        public function sendOutboundPayment(MerchantAccountContextDto $account, array $payment): array
        {
            return ['provider' => $this->key, 'status' => 'sent', 'payment' => $payment];
        }

        public function sendOutboundBatch(MerchantAccountContextDto $account, string $title, array $payments, ?string $scheduleFor = null): array
        {
            return ['provider' => $this->key, 'title' => $title, 'count' => count($payments), 'schedule_for' => $scheduleFor];
        }
    };
}

it('registers payment core config, singletons, and migrations via service provider', function () {
    $registry1 = app(PaymentGatewayRegistryContract::class);
    $registry2 = app(PaymentGatewayRegistryContract::class);
    $orchestrator1 = app(PaymentOrchestrator::class);
    $orchestrator2 = app(PaymentOrchestrator::class);

    expect($registry1)->toBeInstanceOf(PaymentGatewayRegistry::class)
        ->and($registry1)->toBe($registry2)
        ->and($orchestrator1)->toBe($orchestrator2)
        ->and(config('payment-core'))->toBeArray();
});

it('registers, normalizes, checks, resolves, and lists adapters in PaymentGatewayRegistry', function () {
    $registry = new PaymentGatewayRegistry();
    $adapter = makeMinimalAdapter('  Stripe  ');

    $registry->register($adapter);

    expect($registry->has('stripe'))->toBeTrue()
        ->and($registry->has(' STRIPE '))->toBeTrue()
        ->and($registry->has('unknown'))->toBeFalse()
        ->and($registry->resolve('STRIPE'))->toBe($adapter)
        ->and($registry->all())->toBe(['stripe' => $adapter]);
});

it('rejects empty provider keys and unknown adapters in PaymentGatewayRegistry', function () {
    $registry = new PaymentGatewayRegistry();

    expect(fn () => $registry->register(makeMinimalAdapter('   ')))
        ->toThrow(InvalidArgumentException::class, 'Payment provider key cannot be empty.');

    expect(fn () => $registry->resolve('missing'))
        ->toThrow(InvalidArgumentException::class, 'Payment gateway adapter [missing] is not registered.');
});

it('delegates createIntent, capture, refund, and verifyWebhook through PaymentOrchestrator', function () {
    $registry = new PaymentGatewayRegistry();
    $registry->register(makeFullAdapter('demo'));
    $orchestrator = new PaymentOrchestrator($registry);

    $account = new MerchantAccountContextDto('tenant_1', 'demo');
    $amount = new MoneyAmount(5000, 'USD');

    $intent = $orchestrator->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_1'));
    $capture = $orchestrator->capture(new CapturePaymentCommand($account, $intent->providerIntentId, 'idem_2', $amount));
    $refund = $orchestrator->refund(new RefundPaymentCommand($account, $intent->providerIntentId, $amount, 'idem_3'));
    $webhook = $orchestrator->verifyWebhook(new RawWebhookPayloadDto('demo', '{}', [], 'secret'));
    $outbound = $orchestrator->sendOutboundPayment($account, ['amount' => 1000, 'currency' => 'USD']);
    $batch = $orchestrator->sendOutboundBatch($account, 'May Payouts', [['amount' => 1000]], '2026-05-01');

    expect($intent->providerIntentId)->toBe('pi_idem_1')
        ->and($capture->providerCaptureId)->toBe('cap_1')
        ->and($refund->providerRefundId)->toBe('ref_1')
        ->and($webhook->providerEventId)->toBe('evt_1')
        ->and($outbound)->toBe(['provider' => 'demo', 'status' => 'sent', 'payment' => ['amount' => 1000, 'currency' => 'USD']])
        ->and($batch)->toBe(['provider' => 'demo', 'title' => 'May Payouts', 'count' => 1, 'schedule_for' => '2026-05-01']);
});

it('throws RuntimeException when resolved adapter does not implement required capability contracts', function () {
    $registry = new PaymentGatewayRegistry();
    $registry->register(makeMinimalAdapter('bare'));
    $orchestrator = new PaymentOrchestrator($registry);

    $account = new MerchantAccountContextDto('tenant_1', 'bare');
    $amount = new MoneyAmount(1000, 'USD');

    expect(fn () => $orchestrator->createIntent(new CreatePaymentIntentCommand($account, $amount, 'idem_1')))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support payment intents.');

    expect(fn () => $orchestrator->capture(new CapturePaymentCommand($account, 'pi_1', 'idem_2', $amount)))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support captures.');

    expect(fn () => $orchestrator->refund(new RefundPaymentCommand($account, 'pi_1', $amount, 'idem_3')))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support refunds.');

    expect(fn () => $orchestrator->verifyWebhook(new RawWebhookPayloadDto('bare', '{}', [], 'secret')))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support webhook verification.');

    expect(fn () => $orchestrator->sendOutboundPayment($account, ['amount' => 100]))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support outbound payments.');

    expect(fn () => $orchestrator->sendOutboundBatch($account, 'Batch', [['amount' => 100]]))
        ->toThrow(RuntimeException::class, 'Adapter [bare] does not support outbound payments.');
});
