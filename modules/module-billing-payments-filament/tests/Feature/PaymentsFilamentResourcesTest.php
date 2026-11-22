<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Invoicing\Actions\AddInvoiceLine;
use Liberu\Billing\Invoicing\Actions\CreateInvoice;
use Liberu\Billing\Invoicing\Actions\FinalizeInvoice;
use Liberu\Billing\Payments\Actions\CreatePayment;
use Liberu\Billing\Payments\Contracts\GatewayDriver;
use Liberu\Billing\Payments\Filament\PaymentsFilamentPlugin;
use Liberu\Billing\Payments\Filament\Resources\PaymentAllocationResource;
use Liberu\Billing\Payments\Filament\Resources\PaymentAllocationResource\Pages\ListPaymentAllocations;
use Liberu\Billing\Payments\Filament\Resources\PaymentDisputeResource\Pages\ListPaymentDisputes;
use Liberu\Billing\Payments\Filament\Resources\PaymentMandateResource\Pages\CreatePaymentMandate;
use Liberu\Billing\Payments\Filament\Resources\PaymentMandateResource\Pages\ListPaymentMandates;
use Liberu\Billing\Payments\Filament\Resources\PaymentMethodResource\Pages\CreatePaymentMethod;
use Liberu\Billing\Payments\Filament\Resources\PaymentMethodResource\Pages\ListPaymentMethods;
use Liberu\Billing\Payments\Filament\Resources\PaymentReconciliationResource\Pages\ListPaymentReconciliations;
use Liberu\Billing\Payments\Filament\Resources\PaymentRefundResource\Pages\ListPaymentRefunds;
use Liberu\Billing\Payments\Filament\Resources\PaymentResource;
use Liberu\Billing\Payments\Filament\Resources\PaymentResource\Pages\CreatePayment as CreatePaymentPage;
use Liberu\Billing\Payments\Filament\Resources\PaymentResource\Pages\ListPayments;
use Liberu\Billing\Payments\Models\Payment;
use Liberu\Billing\Payments\Models\PaymentAllocation;
use Liberu\Billing\Payments\Models\PaymentDispute;
use Liberu\Billing\Payments\Models\PaymentMandate;
use Liberu\Billing\Payments\Models\PaymentMethod;
use Liberu\Billing\Payments\Models\PaymentReconciliation;
use Liberu\Billing\Payments\Models\PaymentRefund;
use Liberu\Billing\Payments\Services\GatewayManager;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates and manages payments, methods, mandates, and records through the Filament resources', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $actor->switchTeam($actor->ownedTeams()->sole());
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.payments.read', 'billing.payments.write']);

    $invoice = app(CreateInvoice::class)->execute(['team_id' => $actor->current_team_id, 'currency' => 'USD']);
    app(AddInvoiceLine::class)->execute($invoice, 'Plan', 1, 1000);
    $invoice = app(FinalizeInvoice::class)->execute($invoice->refresh());

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($actor->currentTeam);

    $plugin = PaymentsFilamentPlugin::make();
    expect($plugin->getId())->toBe('module-billing-payments-filament');
    $plugin->register($panel);
    $plugin->boot($panel);

    app(GatewayManager::class)->register('filament-gw', new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            return ['reference' => 'fil-cap-1'];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            return ['reference' => 'fil-ref-1'];
        }
    });

    Livewire::test(CreatePaymentPage::class)->fillForm([
        'amount_minor' => 1000,
        'currency' => 'USD',
        'gateway' => 'filament-gw',
        'payment_method' => 'card',
    ])->call('create')->assertHasNoFormErrors();

    $payment = Payment::query()->where('gateway', 'filament-gw')->sole();
    $foreignPayment = app(CreatePayment::class)->execute([
        'team_id' => $actor->current_team_id + 99,
        'amount_minor' => 500,
        'currency' => 'USD',
    ]);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertCanNotSeeTableRecords([$foreignPayment])
        ->callTableAction('capture', $payment)
        ->callTableAction('allocate', $payment, data: ['invoice_id' => $invoice->getKey(), 'amount_minor' => 400])
        ->callTableAction('refund', $payment, data: ['amount_minor' => 200, 'reason' => 'partial refund'])
        ->callTableAction('dispute', $payment, data: ['amount_minor' => 100, 'reason' => 'claim'])
        ->callTableAction('reconcile', $payment, data: ['provider_reference' => 'fil-cap-1']);

    $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->sole();
    $refund = PaymentRefund::query()->where('payment_id', $payment->id)->sole();
    $dispute = PaymentDispute::query()->where('payment_id', $payment->id)->sole();
    $reconciliation = PaymentReconciliation::query()->where('payment_id', $payment->id)->sole();

    Livewire::test(ListPaymentAllocations::class)->assertCanSeeTableRecords([$allocation]);
    Livewire::test(ListPaymentRefunds::class)->assertCanSeeTableRecords([$refund]);
    Livewire::test(ListPaymentDisputes::class)->assertCanSeeTableRecords([$dispute]);
    Livewire::test(ListPaymentReconciliations::class)->assertCanSeeTableRecords([$reconciliation]);

    Livewire::test(CreatePaymentMethod::class)->fillForm([
        'type' => 'card',
        'provider' => 'stripe',
        'display_name' => 'Corporate Card',
        'last_four' => '1234',
    ])->call('create')->assertHasNoFormErrors();

    $method = PaymentMethod::query()->where('last_four', '1234')->sole();

    Livewire::test(ListPaymentMethods::class)
        ->assertCanSeeTableRecords([$method])
        ->callTableAction('status', $method, data: ['status' => 'inactive']);
    expect($method->fresh()->status->value)->toBe('inactive');

    Livewire::test(CreatePaymentMandate::class)->fillForm([
        'payment_method_id' => $method->id,
        'provider' => 'stripe',
        'provider_reference' => 'fil-mandate-1',
    ])->call('create')->assertHasNoFormErrors();

    $mandate = PaymentMandate::query()->where('provider_reference', 'fil-mandate-1')->sole();

    Livewire::test(ListPaymentMandates::class)
        ->assertCanSeeTableRecords([$mandate])
        ->callTableAction('status', $mandate, data: ['status' => 'active']);
    expect($mandate->fresh()->status)->toBe('active');
});

it('restricts Filament queries to shared records without a current team', function (): void {
    $shared = app(CreatePayment::class)->execute(['team_id' => null, 'amount_minor' => 250, 'currency' => 'USD']);
    $private = app(CreatePayment::class)->execute(['team_id' => 42, 'amount_minor' => 300, 'currency' => 'USD']);
    $sharedAllocation = PaymentAllocation::query()->create(['payment_id' => $shared->id, 'amount_minor' => 50, 'currency' => 'USD']);
    PaymentAllocation::query()->create(['payment_id' => $private->id, 'amount_minor' => 50, 'currency' => 'USD']);

    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.payments.read']);

    expect(PaymentResource::getEloquentQuery()->pluck('id')->all())->toBe([$shared->id])
        ->and(PaymentAllocationResource::getEloquentQuery()->pluck('id')->all())->toBe([$sharedAllocation->id]);
});
