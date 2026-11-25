<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Invoicing\Enums\InvoiceStatus;
use Liberu\Billing\Invoicing\Livewire\Components\InvoiceList;
use Liberu\Billing\Invoicing\Livewire\Components\InvoiceSchedules;
use Liberu\Billing\Invoicing\Livewire\Components\InvoiceSupportList;
use Liberu\Billing\Invoicing\Livewire\Components\PaymentPlans;
use Liberu\Billing\Invoicing\Models\Invoice;
use Liberu\Billing\Invoicing\Models\InvoiceSchedule;
use Liberu\Billing\Invoicing\Models\InvoiceSupport;
use Liberu\Billing\Invoicing\Models\PaymentPlan;
use Livewire\Livewire;

it('renders and manages all invoicing Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.invoicing.read', 'billing.invoicing.write']);

    // InvoiceList: create draft invoice
    $invoiceList = Livewire::test(InvoiceList::class)
        ->assertOk()
        ->set('currency', 'USD')
        ->call('create')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('team_id', $team->id)->latest('id')->firstOrFail();

    // InvoiceSupportList: add line and create support record
    Livewire::test(InvoiceSupportList::class)
        ->assertOk()
        ->set('selectedInvoiceId', $invoice->id)
        ->set('description', 'Consulting Retainer')
        ->set('quantity', 2)
        ->set('unitAmountMinor', 2500)
        ->set('taxRate', 10)
        ->call('addLine')
        ->assertHasNoErrors()
        ->set('supportType', 'tax')
        ->set('supportAmountMinor', 500)
        ->set('supportCurrency', 'USD')
        ->call('createSupport')
        ->assertHasNoErrors();

    expect(InvoiceSupport::query()->where('invoice_id', $invoice->id)->where('type', 'tax')->exists())->toBeTrue();

    // InvoiceList: finalize, document, deliver, adjust, applyLateFee
    $invoiceList
        ->call('finalize', $invoice->id)
        ->assertHasNoErrors()
        ->call('document', $invoice->id)
        ->assertHasNoErrors()
        ->set('deliveryDestination', 'lw-billing@example.com')
        ->call('deliver', $invoice->id)
        ->assertHasNoErrors()
        ->set('selectedInvoiceId', $invoice->id)
        ->set('adjustmentMinor', 300)
        ->set('adjustmentReason', 'Expedited fee')
        ->call('adjust')
        ->assertHasNoErrors();

    $invoice->update(['due_at' => now()->subDays(3)]);

    $invoiceList
        ->set('selectedInvoiceId', $invoice->id)
        ->set('lateFeeMinor', 200)
        ->call('applyLateFee')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Finalized)
        ->and($invoice->fresh()->total_minor)->toBe(6000);

    // InvoiceSchedules: createSchedule and runSchedule
    $schedulesComponent = Livewire::test(InvoiceSchedules::class)
        ->assertOk()
        ->set('frequency', 'monthly')
        ->set('nextRunAt', now()->subMinute()->toDateTimeString())
        ->set('active', true)
        ->call('createSchedule')
        ->assertHasNoErrors();

    $schedule = InvoiceSchedule::query()->where('team_id', $team->id)->latest('id')->firstOrFail();

    $schedulesComponent
        ->set('selectedScheduleId', $schedule->id)
        ->call('runSchedule')
        ->assertHasNoErrors();

    // PaymentPlans: createPlan and runPlan
    $plansComponent = Livewire::test(PaymentPlans::class)
        ->assertOk()
        ->set('invoiceId', $invoice->id)
        ->set('totalInstallments', 2)
        ->set('frequency', 'monthly')
        ->call('createPlan')
        ->assertHasNoErrors();

    $plan = PaymentPlan::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
    $plan->update(['next_due_at' => now()->subDay()]);

    $plansComponent
        ->set('selectedPlanId', $plan->id)
        ->call('runPlan')
        ->assertHasNoErrors();

    expect($plan->fresh()->generated_installments)->toBe(1);
});
