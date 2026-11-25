<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Payments\Contracts\GatewayDriver;
use Liberu\Billing\Payments\Livewire\Components\PaymentList;
use Liberu\Billing\Payments\Livewire\Components\PaymentMethods;
use Liberu\Billing\Payments\Livewire\Components\PaymentOperations;
use Liberu\Billing\Payments\Models\Payment;
use Liberu\Billing\Payments\Models\PaymentDispute;
use Liberu\Billing\Payments\Models\PaymentMandate;
use Liberu\Billing\Payments\Models\PaymentMethod;
use Liberu\Billing\Payments\Models\PaymentReconciliation;
use Liberu\Billing\Payments\Services\GatewayManager;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('manages payments, methods, mandates, and operations through the published Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.payments.read', 'billing.payments.write']);

    app(GatewayManager::class)->register('lw-gw', new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            return ['reference' => 'lw-cap-1'];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            return ['reference' => 'lw-ref-1'];
        }
    });

    Livewire::test(PaymentList::class)
        ->set('showCreate', true)
        ->set('amountMinor', 1000)
        ->set('currency', 'USD')
        ->call('createPayment')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false);

    $payment = Payment::query()->where('team_id', $user->current_team_id)->sole();
    $payment->update(['gateway' => 'lw-gw']);

    Livewire::test(PaymentMethods::class)
        ->set('type', 'card')
        ->set('provider', 'stripe')
        ->set('displayName', 'Main Visa')
        ->set('lastFour', '4242')
        ->set('isDefault', true)
        ->call('createMethod')
        ->assertHasNoErrors();

    $method = PaymentMethod::query()->where('team_id', $user->current_team_id)->sole();

    Livewire::test(PaymentMethods::class)
        ->set('selectedPaymentMethodId', $method->id)
        ->set('mandateProvider', 'stripe')
        ->set('mandateProviderReference', 'lw-mandate-1')
        ->set('mandateStatus', 'pending')
        ->call('createMandate')
        ->assertHasNoErrors();

    expect(PaymentMandate::query()->where('payment_method_id', $method->id)->exists())->toBeTrue();

    Livewire::test(PaymentOperations::class)
        ->set('selectedPaymentId', $payment->id)
        ->call('capture')
        ->assertHasNoErrors()
        ->set('selectedPaymentId', $payment->id)
        ->set('amountMinor', 400)
        ->call('allocate')
        ->assertHasNoErrors()
        ->set('selectedPaymentId', $payment->id)
        ->set('amountMinor', 200)
        ->set('reason', 'partial refund')
        ->call('refund')
        ->assertHasNoErrors()
        ->set('selectedPaymentId', $payment->id)
        ->set('amountMinor', 100)
        ->set('reason', 'chargeback')
        ->call('dispute')
        ->assertHasNoErrors()
        ->set('selectedPaymentId', $payment->id)
        ->set('providerReference', 'lw-cap-1')
        ->call('reconcile')
        ->assertHasNoErrors();

    expect(PaymentDispute::query()->where('payment_id', $payment->id)->exists())->toBeTrue()
        ->and(PaymentReconciliation::query()->where('payment_id', $payment->id)->exists())->toBeTrue();
});

it('renders Livewire payment components when the user has no current team', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.payments.read', 'billing.payments.write']);

    $sharedPayment = Payment::query()->create([
        'team_id' => null,
        'amount_minor' => 500,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    Livewire::test(PaymentList::class)->assertOk();
    Livewire::test(PaymentOperations::class)
        ->assertOk()
        ->set('selectedPaymentId', $sharedPayment->id)
        ->set('providerReference', 'shared-ref-1')
        ->call('reconcile')
        ->assertHasNoErrors();
});
