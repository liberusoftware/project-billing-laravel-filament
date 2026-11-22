<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Invoicing\Actions\AddInvoiceLine;
use Liberu\Billing\Invoicing\Actions\CreateInvoice;
use Liberu\Billing\Invoicing\Actions\FinalizeInvoice;
use Liberu\Billing\Payments\Actions\AllocatePayment;
use Liberu\Billing\Payments\Actions\CapturePayment;
use Liberu\Billing\Payments\Actions\CreatePayment;
use Liberu\Billing\Payments\Actions\CreatePaymentMandate;
use Liberu\Billing\Payments\Actions\CreatePaymentMethod;
use Liberu\Billing\Payments\Actions\OpenDispute;
use Liberu\Billing\Payments\Actions\ReconcilePayment;
use Liberu\Billing\Payments\Actions\RefundPayment;
use Liberu\Billing\Payments\Actions\TransitionPaymentMandate;
use Liberu\Billing\Payments\Contracts\GatewayDriver;
use Liberu\Billing\Payments\Enums\PaymentStatus;
use Liberu\Billing\Payments\Enums\ReconciliationStatus;
use Liberu\Billing\Payments\Events\PaymentCaptured;
use Liberu\Billing\Payments\Events\PaymentFailed;
use Liberu\Billing\Payments\Events\PaymentRefundFailed;
use Liberu\Billing\Payments\Models\Payment;
use Liberu\Billing\Payments\Models\PaymentAllocation;
use Liberu\Billing\Payments\Models\PaymentRefund;
use Liberu\Billing\Payments\Policies\PaymentMandatePolicy;
use Liberu\Billing\Payments\Policies\PaymentMethodPolicy;
use Liberu\Billing\Payments\Policies\PaymentPolicy;
use Liberu\Billing\Payments\Policies\PaymentRecordPolicy;
use Liberu\Billing\Payments\Queries\ListPayments;
use Liberu\Billing\Payments\Services\GatewayManager;
use Liberu\Billing\Payments\Support\CustomerReference;

uses(RefreshDatabase::class);

it('validates payment, payment method, mandate, and gateway manager edge cases', function (): void {
    expect(fn () => app(CreatePayment::class)->execute(['amount_minor' => 0, 'currency' => 'USD']))
        ->toThrow(InvalidArgumentException::class, 'Payment amount and currency are invalid.');

    expect(fn () => app(CreatePayment::class)->execute(['amount_minor' => 100, 'currency' => 'US']))
        ->toThrow(InvalidArgumentException::class, 'Payment amount and currency are invalid.');

    expect(fn () => app(CreatePaymentMethod::class)->execute(['type' => '', 'provider' => 'stripe']))
        ->toThrow(InvalidArgumentException::class, 'Payment method type and provider are required.');

    expect(fn () => app(CreatePaymentMandate::class)->execute(['payment_method_id' => 0, 'provider' => 'stripe']))
        ->toThrow(InvalidArgumentException::class, 'Payment method and provider are required.');

    $team = Team::factory()->create();
    $customerA = Customer::factory()->create(['team_id' => $team->getKey()]);
    $customerB = Customer::factory()->create(['team_id' => $team->getKey()]);

    $method = app(CreatePaymentMethod::class)->execute([
        'team_id' => $team->getKey(),
        'customer_id' => $customerA->getKey(),
        'type' => 'card',
        'provider' => 'stripe',
    ]);

    expect(fn () => app(CreatePaymentMandate::class)->execute([
        'team_id' => $team->getKey(),
        'customer_id' => $customerB->getKey(),
        'payment_method_id' => $method->getKey(),
        'provider' => 'stripe',
    ]))->toThrow(InvalidArgumentException::class, 'Payment method customer reference is invalid.');

    $mandate = app(CreatePaymentMandate::class)->execute([
        'team_id' => $team->getKey(),
        'customer_id' => $customerA->getKey(),
        'payment_method_id' => $method->getKey(),
        'provider' => 'stripe',
    ]);

    expect(fn () => app(TransitionPaymentMandate::class)->execute($mandate, 'invalid'))
        ->toThrow(InvalidArgumentException::class, 'Payment mandate status is invalid.');

    $manager = app(GatewayManager::class);
    $driver = new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            return ['reference' => 'cap'];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            return ['reference' => 'ref'];
        }
    };

    expect(fn () => $manager->register('   ', $driver))
        ->toThrow(InvalidArgumentException::class, 'Payment gateway name cannot be empty.');
    expect(fn () => $manager->driver('missing-gateway'))
        ->toThrow(InvalidArgumentException::class, 'is not registered.');
});

it('handles capture and refund concurrency and state-transition branches', function (): void {
    Event::fake([PaymentCaptured::class, PaymentFailed::class, PaymentRefundFailed::class]);

    $paymentNoGateway = app(CreatePayment::class)->execute(['amount_minor' => 500, 'currency' => 'USD']);
    expect(fn () => app(CapturePayment::class)->execute($paymentNoGateway))
        ->toThrow(LogicException::class, 'Payment is not capturable.');

    $alreadyCaptured = app(CreatePayment::class)->execute(['amount_minor' => 500, 'currency' => 'USD', 'gateway' => 'ok']);
    $alreadyCaptured->update(['status' => PaymentStatus::Captured]);
    expect(app(CapturePayment::class)->execute($alreadyCaptured->refresh())->is($alreadyCaptured))->toBeTrue();

    $manager = app(GatewayManager::class);

    $manager->register('race-captured', new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            Payment::query()->whereKey($payment->getKey())->update(['status' => PaymentStatus::Captured]);

            return ['reference' => 'race-1'];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            return ['reference' => 'ref-1'];
        }
    });

    $raceCaptured = app(CreatePayment::class)->execute(['amount_minor' => 500, 'currency' => 'USD', 'gateway' => 'race-captured']);
    expect(app(CapturePayment::class)->execute($raceCaptured)->status)->toBe(PaymentStatus::Captured);

    $manager->register('race-failed', new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            Payment::query()->whereKey($payment->getKey())->update(['status' => PaymentStatus::Failed]);

            return ['reference' => 'race-2'];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            Payment::query()->whereKey($payment->getKey())->update(['status' => PaymentStatus::Failed]);

            return ['reference' => 'ref-2'];
        }
    });

    $raceFailed = app(CreatePayment::class)->execute(['amount_minor' => 500, 'currency' => 'USD', 'gateway' => 'race-failed']);
    expect(fn () => app(CapturePayment::class)->execute($raceFailed))
        ->toThrow(LogicException::class, 'Payment is no longer capturable.');

    $raceRefundSuccess = app(CreatePayment::class)->execute(['amount_minor' => 500, 'currency' => 'USD', 'gateway' => 'race-failed']);
    $raceRefundSuccess->update(['status' => PaymentStatus::Captured]);
    expect(fn () => app(RefundPayment::class)->execute($raceRefundSuccess->refresh(), 100))
        ->toThrow(InvalidArgumentException::class, 'Refund amount or payment state is invalid.');

    $manager->register('race-throw', new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            Payment::query()->whereKey($payment->getKey())->update(['status' => PaymentStatus::Captured]);

            throw new RuntimeException('Capture boom after state change.');
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            Payment::query()->whereKey($payment->getKey())->update(['status' => PaymentStatus::Failed]);

            throw new RuntimeException('Refund boom after state change.');
        }
    });

    $raceCaptureThrow = app(CreatePayment::class)->execute(['amount_minor' => 500, 'currency' => 'USD', 'gateway' => 'race-throw']);
    expect(fn () => app(CapturePayment::class)->execute($raceCaptureThrow))
        ->toThrow(RuntimeException::class, 'Capture boom after state change.');
    expect($raceCaptureThrow->refresh()->status)->toBe(PaymentStatus::Captured);

    $raceRefundThrow = app(CreatePayment::class)->execute(['amount_minor' => 500, 'currency' => 'USD', 'gateway' => 'race-throw']);
    $raceRefundThrow->update(['status' => PaymentStatus::Captured]);
    expect(fn () => app(RefundPayment::class)->execute($raceRefundThrow->refresh(), 100))
        ->toThrow(RuntimeException::class, 'Refund boom after state change.');
    expect(PaymentRefund::query()->where('payment_id', $raceRefundThrow->getKey())->count())->toBe(0);
});

it('handles allocation invoice validation, missing tables, reconciliation mismatch, and model relations', function (): void {
    $payment = app(CreatePayment::class)->execute(['team_id' => 10, 'amount_minor' => 1000, 'currency' => 'USD', 'gateway' => 'test-rel']);
    $payment->update(['status' => PaymentStatus::Captured]);

    $draftInvoice = app(CreateInvoice::class)->execute(['team_id' => 10, 'currency' => 'USD']);
    expect(fn () => app(AllocatePayment::class)->execute($payment->refresh(), 100, $draftInvoice->getKey()))
        ->toThrow(InvalidArgumentException::class, 'Payment invoice reference is invalid.');

    $eurInvoice = app(CreateInvoice::class)->execute(['team_id' => 10, 'currency' => 'EUR']);
    app(AddInvoiceLine::class)->execute($eurInvoice, 'Item', 1, 1000);
    $eurInvoice = app(FinalizeInvoice::class)->execute($eurInvoice->refresh());
    expect(fn () => app(AllocatePayment::class)->execute($payment->refresh(), 100, $eurInvoice->getKey()))
        ->toThrow(InvalidArgumentException::class, 'Payment invoice reference is invalid.');

    $allocation = app(AllocatePayment::class)->execute($payment->refresh(), 200);
    expect($allocation->payment->is($payment))->toBeTrue();

    $reconciliation = app(ReconcilePayment::class)->execute($payment->refresh(), 'mismatch-ref', false, 'Amount differs');
    expect($reconciliation->status)->toBe(ReconciliationStatus::Mismatch)
        ->and($reconciliation->payment->is($payment))->toBeTrue();

    app(GatewayManager::class)->register('test-rel', new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            return ['reference' => 'cap-rel'];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            return ['reference' => 'ref-rel'];
        }
    });

    $refund = app(RefundPayment::class)->execute($payment->refresh(), 1000);
    expect($refund->payment->is($payment))->toBeTrue()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Refunded);

    $paymentForDispute = app(CreatePayment::class)->execute(['team_id' => 10, 'amount_minor' => 500, 'currency' => 'USD']);
    $paymentForDispute->update(['status' => PaymentStatus::Captured]);
    $dispute = app(OpenDispute::class)->execute($paymentForDispute->refresh(), 100, 'duplicate charge');
    expect($dispute->payment->is($paymentForDispute))->toBeTrue();

    Schema::shouldReceive('hasTable')->with('billing_invoices')->once()->andReturn(false);
    $paymentNoInvoiceTable = app(CreatePayment::class)->execute(['team_id' => 10, 'amount_minor' => 500, 'currency' => 'USD']);
    $paymentNoInvoiceTable->update(['status' => PaymentStatus::Captured]);
    expect(fn () => app(AllocatePayment::class)->execute($paymentNoInvoiceTable->refresh(), 100, 999))
        ->toThrow(InvalidArgumentException::class, 'Payment invoice reference is invalid.');

    Schema::shouldReceive('hasTable')->with('customers')->once()->andReturn(false);
    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), 1, 10))
        ->toThrow(InvalidArgumentException::class, 'Customer reference is invalid.');
});

it('scopes ListPayments for null and team-specific queries and checks all payment policies', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    Sanctum::actingAs($user, ['billing.payments.read', 'billing.payments.write']);

    $sharedPayment = app(CreatePayment::class)->execute(['team_id' => null, 'amount_minor' => 100, 'currency' => 'USD']);
    $teamPayment = app(CreatePayment::class)->execute(['team_id' => $user->current_team_id, 'amount_minor' => 200, 'currency' => 'USD']);
    $otherPayment = app(CreatePayment::class)->execute(['team_id' => $otherTeam->id, 'amount_minor' => 300, 'currency' => 'USD']);

    $nullResults = app(ListPayments::class)->execute(null)->pluck('id')->all();
    expect($nullResults)->toContain($sharedPayment->id)
        ->not->toContain($teamPayment->id)
        ->not->toContain($otherPayment->id);

    $teamResults = app(ListPayments::class)->execute($user->current_team_id)->pluck('id')->all();
    expect($teamResults)->toContain($sharedPayment->id, $teamPayment->id)
        ->not->toContain($otherPayment->id);

    $method = app(CreatePaymentMethod::class)->execute(['team_id' => $user->current_team_id, 'type' => 'card', 'provider' => 'stripe']);
    $otherMethod = app(CreatePaymentMethod::class)->execute(['team_id' => $otherTeam->id, 'type' => 'card', 'provider' => 'stripe']);
    $mandate = app(CreatePaymentMandate::class)->execute(['team_id' => $user->current_team_id, 'payment_method_id' => $method->id, 'provider' => 'stripe']);
    $otherMandate = app(CreatePaymentMandate::class)->execute(['team_id' => $otherTeam->id, 'payment_method_id' => $otherMethod->id, 'provider' => 'stripe']);

    $allocation = PaymentAllocation::query()->create(['payment_id' => $teamPayment->id, 'amount_minor' => 50, 'currency' => 'USD']);
    $otherAllocation = PaymentAllocation::query()->create(['payment_id' => $otherPayment->id, 'amount_minor' => 50, 'currency' => 'USD']);

    $paymentPolicy = new PaymentPolicy();
    expect($paymentPolicy->viewAny($user))->toBeTrue()
        ->and($paymentPolicy->view($user, $teamPayment))->toBeTrue()
        ->and($paymentPolicy->view($user, $otherPayment))->toBeFalse()
        ->and($paymentPolicy->create($user))->toBeTrue()
        ->and($paymentPolicy->update($user, $teamPayment))->toBeTrue()
        ->and($paymentPolicy->update($user, $otherPayment))->toBeFalse();

    $methodPolicy = new PaymentMethodPolicy();
    expect($methodPolicy->viewAny($user))->toBeTrue()
        ->and($methodPolicy->view($user, $method))->toBeTrue()
        ->and($methodPolicy->view($user, $otherMethod))->toBeFalse()
        ->and($methodPolicy->create($user))->toBeTrue()
        ->and($methodPolicy->update($user, $method))->toBeTrue()
        ->and($methodPolicy->update($user, $otherMethod))->toBeFalse();

    $mandatePolicy = new PaymentMandatePolicy();
    expect($mandatePolicy->viewAny($user))->toBeTrue()
        ->and($mandatePolicy->view($user, $mandate))->toBeTrue()
        ->and($mandatePolicy->view($user, $otherMandate))->toBeFalse()
        ->and($mandatePolicy->create($user))->toBeTrue()
        ->and($mandatePolicy->update($user, $mandate))->toBeTrue()
        ->and($mandatePolicy->update($user, $otherMandate))->toBeFalse();

    $recordPolicy = new PaymentRecordPolicy();
    expect($recordPolicy->viewAny($user))->toBeTrue()
        ->and($recordPolicy->create($user))->toBeTrue()
        ->and($recordPolicy->view($user, $allocation))->toBeTrue()
        ->and($recordPolicy->view($user, $otherAllocation))->toBeFalse()
        ->and($recordPolicy->viewAny(null))->toBeFalse();
});
