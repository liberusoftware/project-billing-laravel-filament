<?php

declare(strict_types=1);

use App\Events\InvoiceStatusChanged;
use App\Mail\InvoiceGenerated;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Invoice;
use App\Models\InvoiceTemplate;
use App\Models\LateFeeConfiguration;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\RecurringBillingConfiguration;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\PaymentGatewayService;
use App\Services\TaxService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

it('covers Invoice boot defaults, delete hook, and model relationships', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->id;
    $customer = Customer::factory()->create(['team_id' => $teamId]);

    Currency::query()->firstOrCreate(
        ['code' => 'USD'],
        [
            'name' => 'US Dollar',
            'symbol' => '$',
            'exchange_rate' => 1.0,
            'is_enabled' => true,
            'is_base' => true,
            'decimal_precision' => 2,
        ]
    );

    $discount = Discount::query()->create([
        'code' => 'SAVE10',
        'name' => 'Save 10%',
        'type' => 'percentage',
        'value' => 10,
        'currency' => 'USD',
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'is_active' => true,
    ]);

    $template = InvoiceTemplate::query()->create([
        'name' => 'Standard',
        'company_name' => 'Standard Co',
        'color_scheme' => '#333333',
        'is_default' => false,
        'team_id' => $teamId,
    ]);

    // Trigger creating hook defaulting status to 'pending', currency to 'USD', and invoice_number
    $parentInvoice = Invoice::query()->create([
        'customer_id' => $customer->id,
        'issue_date' => now(),
        'due_date' => now()->addDays(15),
        'total_amount' => 200.00,
        'team_id' => $teamId,
        'discount_id' => $discount->id,
        'invoice_template_id' => $template->id,
    ]);

    $childInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'parent_invoice_id' => $parentInvoice->id,
        'is_installment' => true,
        'team_id' => $teamId,
    ]);

    expect($parentInvoice->status)->toBe('pending')
        ->and($parentInvoice->currency()->first()?->code)->toBe('USD')
        ->and($parentInvoice->discount->is($discount))->toBeTrue()
        ->and($parentInvoice->template->is($template))->toBeTrue()
        ->and($childInvoice->parentInvoice->is($parentInvoice))->toBeTrue();

    // Trigger deleted hook (AuditLogService::log('invoice_deleted', ...))
    $childInvoice->delete();
    expect(Invoice::query()->find($childInvoice->id))->toBeNull();
});

it('covers Invoice processPayment, updateStatus reversal, tax, currency conversion, email, and PDF', function (): void {
    Mail::fake();

    $customer = Customer::factory()->create(['email' => 'payer@example.test']);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'total_amount' => 100.00,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    // Invalid amounts
    expect(fn () => $invoice->processPayment('card', 0))->toThrow(Exception::class, 'Invalid payment amount')
        ->and(fn () => $invoice->processPayment('card', 150.00))->toThrow(Exception::class, 'Invalid payment amount');

    // Failed gateway payment
    $this->mock(PaymentGatewayService::class, function ($mock): void {
        $mock->shouldReceive('processPayment')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Card declined']);
    });

    expect(fn () => $invoice->processPayment('card', 50.00))
        ->toThrow(Exception::class, 'Card declined');

    // Successful gateway payment
    $gateway = PaymentGateway::query()->create([
        'name' => 'Gateway',
        'api_key' => 'k',
        'secret_key' => 's',
    ]);
    $this->mock(PaymentGatewayService::class, function ($mock) use ($gateway): void {
        $mock->shouldReceive('processPayment')
            ->once()
            ->andReturnUsing(function (Payment $payment) use ($gateway): array {
                $payment->payment_gateway_id = $gateway->id;

                return ['success' => true, 'transaction_id' => 'txn_inv_1'];
            });
    });

    expect($invoice->processPayment('card', 100.00))->toBeTrue()
        ->and($invoice->fresh()->status)->toBe('paid');

    // updateStatus reverts paid/partially_paid to pending when settled payments become 0
    $invoice->payments()->delete();
    $invoice->updateStatus();
    expect($invoice->fresh()->status)->toBe('pending');

    // calculateTax
    $this->mock(TaxService::class, function ($mock) use ($invoice): void {
        $mock->shouldReceive('calculateTax')
            ->once()
            ->with($invoice)
            ->andReturn(12.50);
    });
    expect($invoice->calculateTax())->toBe(12.50);

    // convertAmountTo & getFormattedAmount
    $this->mock(CurrencyService::class, function ($mock): void {
        $mock->shouldReceive('convert')
            ->once()
            ->with(100.0, 'USD', 'EUR')
            ->andReturn(92.0);
    });
    expect($invoice->convertAmountTo('EUR'))->toBe(92.0)
        ->and($invoice->getFormattedAmount())->toBe('100.00 USD');

    // sendInvoiceEmail
    $invoice->sendInvoiceEmail();
    Mail::assertSent(InvoiceGenerated::class);

    // generatePdf
    $pdfMock = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    Pdf::shouldReceive('loadView')
        ->once()
        ->with('invoices.pdf', ['invoice' => $invoice])
        ->andReturn($pdfMock);

    expect($invoice->generatePdf())->toBe($pdfMock);
});

it('covers Invoice createPaymentPlan weekly and quarterly frequencies', function (): void {
    Carbon::setTestNow('2026-07-01 12:00:00');

    $weeklyInvoice = Invoice::factory()->create(['total_amount' => 120.00, 'is_installment' => false]);
    $weeklyPlan = $weeklyInvoice->createPaymentPlan(4, 'weekly');
    expect($weeklyPlan->frequency)->toBe('weekly');

    $quarterlyInvoice = Invoice::factory()->create(['total_amount' => 300.00, 'is_installment' => false]);
    $quarterlyPlan = $quarterlyInvoice->createPaymentPlan(3, 'quarterly');
    expect($quarterlyPlan->frequency)->toBe('quarterly');

    Carbon::setTestNow();
});

it('covers Invoice calculateLateFee and remainingLateFee across all branches', function (): void {
    Carbon::setTestNow('2026-07-20 12:00:00');

    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->id;

    // 1. Not overdue -> returns 0
    $notOverdue = Invoice::factory()->create([
        'team_id' => $teamId,
        'status' => 'paid',
        'due_date' => now()->subDays(10),
        'total_amount' => 100.00,
    ]);
    expect($notOverdue->calculateLateFee())->toBe(0);

    // 2. Overdue but no LateFeeConfiguration -> returns 0 and remaining_late_fee is null
    $overdue = Invoice::factory()->create([
        'team_id' => $teamId,
        'status' => 'pending',
        'due_date' => now()->subDays(15),
        'total_amount' => 100.00,
        'late_fee_amount' => 10.00,
    ]);
    expect($overdue->calculateLateFee())->toBe(0)
        ->and($overdue->remaining_late_fee)->toBeNull();

    // 3. Compound percentage config with max_fee_amount
    $config = LateFeeConfiguration::query()->create([
        'team_id' => $teamId,
        'fee_type' => 'percentage',
        'fee_amount' => 20.00,
        'grace_period_days' => 5,
        'max_fee_amount' => 25.00,
        'is_compound' => true,
        'frequency' => 'one-time',
    ]);

    // base = 100 + 10 = 110, 20% = 22, totalFees = 32 > 25 => capped at 15.0
    expect($overdue->calculateLateFee())->toBe(15.0)
        ->and((float) $overdue->remaining_late_fee)->toBe(15.0);

    // 4. Frequency rules when last_late_fee_date is set
    $overdue->forceFill(['last_late_fee_date' => now()->subDays(10)])->save();

    // one-time -> 0
    expect($overdue->calculateLateFee())->toBe(0.0);

    // daily (>= 1 day) -> 15.0
    $config->update(['frequency' => 'daily']);
    expect($overdue->calculateLateFee())->toBe(15.0);

    // weekly (>= 7 days) -> 15.0
    $config->update(['frequency' => 'weekly']);
    expect($overdue->calculateLateFee())->toBe(15.0);

    // monthly (< 30 days) -> 0.0, then >= 30 days -> 15.0
    $config->update(['frequency' => 'monthly']);
    expect($overdue->calculateLateFee())->toBe(0.0);
    $overdue->forceFill(['last_late_fee_date' => now()->subDays(31)])->save();
    expect($overdue->calculateLateFee())->toBe(15.0);

    // unknown frequency (default) -> 0.0 (avoid DB enum CHECK constraint by overriding on retrieve)
    $forceUnknownFrequency = true;
    LateFeeConfiguration::retrieved(function (LateFeeConfiguration $retrieved) use (&$forceUnknownFrequency, $config): void {
        if ($forceUnknownFrequency && $retrieved->id === $config->id) {
            $retrieved->frequency = 'biweekly';
        }
    });

    try {
        expect($overdue->calculateLateFee())->toBe(0.0);
    } finally {
        $forceUnknownFrequency = false;
    }

    Carbon::setTestNow();
});

it('covers Invoice markAsSent, markAsViewed, status accessor, and setupRecurringBilling', function (): void {
    Event::fake([InvoiceStatusChanged::class]);
    Carbon::setTestNow('2026-07-10 12:00:00');

    $invoice = Invoice::factory()->create(['status' => 'pending']);

    $invoice->markAsSent();
    expect($invoice->fresh()->status)->toBe('sent');
    Event::assertDispatched(InvoiceStatusChanged::class, fn (InvoiceStatusChanged $e): bool => $e->status === 'sent');

    $invoice->markAsViewed();
    expect($invoice->fresh()->status)->toBe('viewed');
    Event::assertDispatched(InvoiceStatusChanged::class, fn (InvoiceStatusChanged $e): bool => $e->status === 'viewed');

    // Installment invoice cannot set up recurring billing
    $installment = Invoice::factory()->create(['is_installment' => true]);
    expect(fn () => $installment->setupRecurringBilling('monthly'))
        ->toThrow(Exception::class, 'Cannot set up recurring billing for an installment invoice');

    // billingDay > current day (10)
    $invFutureDay = Invoice::factory()->create(['is_installment' => false]);
    $cfg1 = $invFutureDay->setupRecurringBilling('monthly', 20);
    expect($cfg1)->toBeInstanceOf(RecurringBillingConfiguration::class)
        ->and($invFutureDay->recurringConfiguration->is($cfg1))->toBeTrue()
        ->and($cfg1->next_billing_date->toDateString())->toBe('2026-07-20');

    // billingDay <= current day (10) with monthly
    $invPastDay = Invoice::factory()->create(['is_installment' => false]);
    $cfg2 = $invPastDay->setupRecurringBilling('monthly', 5);
    expect($cfg2->next_billing_date->toDateString())->toBe('2026-08-05');

    // quarterly, yearly, and default frequencies without billingDay
    $invQuarterly = Invoice::factory()->create(['is_installment' => false]);
    expect($invQuarterly->setupRecurringBilling('quarterly')->next_billing_date->toDateString())->toBe('2026-10-10');

    $invYearly = Invoice::factory()->create(['is_installment' => false]);
    expect($invYearly->setupRecurringBilling('yearly')->next_billing_date->toDateString())->toBe('2027-07-10');

    $invDefault = Invoice::factory()->create(['is_installment' => false]);
    expect($invDefault->setupRecurringBilling('custom')->next_billing_date->toDateString())->toBe('2026-08-10');

    Carbon::setTestNow();
});
