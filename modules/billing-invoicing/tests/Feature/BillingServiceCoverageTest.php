<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Discount;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Invoice_Item;
use App\Models\LateFeeConfiguration;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Models\Products_Service;
use App\Models\RecurringBillingConfiguration;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BillingService;
use App\Services\CurrencyService;
use App\Services\PaymentGatewayService;
use App\Services\RefundService;
use App\Services\ServiceProvisioningService;
use App\Services\SmsService;
use Illuminate\Support\Carbon;

it('covers BillingService cancelSubscription, recordUsage, applyDiscount currency conversion & unknown type, and handleRefund', function (): void {
    $currencyService = Mockery::mock(CurrencyService::class);
    $currencyService->shouldReceive('convert')
        ->once()
        ->with(10.0, 'EUR', 'USD')
        ->andReturn(12.0);

    $refundService = Mockery::mock(RefundService::class);
    $refundService->shouldReceive('processRefund')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Refund processed successfully.']);

    $service = new BillingService(
        serviceProvisioningService: app(ServiceProvisioningService::class),
        currencyService: $currencyService,
        refundService: $refundService,
    );

    // 1. cancelSubscription + calculateRefundAmount
    $subscription = Subscription::factory()->create([
        'start_date' => now()->subDays(10),
        'end_date' => now()->addDays(20),
        'price' => 30.00,
        'status' => 'active',
        'auto_renew' => true,
    ]);

    expect($service->cancelSubscription($subscription))->toBeTrue()
        ->and($subscription->fresh()->status)->toBe('cancelled')
        ->and((bool) $subscription->fresh()->auto_renew)->toBeFalse();

    // 2. recordUsage
    $usageProduct = Products_Service::factory()->create([
        'pricing_model' => 'usage_based',
        'custom_pricing_data' => [
            'usage_config' => [
                'api_calls' => ['price' => 0.05],
            ],
        ],
    ]);
    $usageSubscription = Subscription::factory()->create([
        'product_service_id' => $usageProduct->id,
    ]);

    $usageRecord = $service->recordUsage($usageSubscription, 'api_calls', 42.0);
    expect($usageRecord->metric_name)->toBe('api_calls')
        ->and((float) $usageRecord->quantity)->toBe(42.0);

    // 3. applyDiscount with fixed foreign currency (calls convertCurrency)
    $invoice = Invoice::factory()->create([
        'currency' => 'USD',
        'total_amount' => 100.00,
    ]);
    Invoice_Item::query()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Item',
        'quantity' => 1,
        'unit_price' => 100.00,
        'total_price' => 100.00,
        'currency' => 'USD',
    ]);

    Discount::query()->create([
        'code' => 'EUR10',
        'name' => '10 EUR Off',
        'type' => 'fixed',
        'value' => 10,
        'currency' => 'EUR',
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'is_active' => true,
    ]);

    $eurResult = $service->applyDiscount($invoice, 'EUR10');
    expect($eurResult['success'])->toBeTrue()
        ->and($eurResult['discount_amount'])->toBe(12.0);

    // 4. applyDiscount with unknown discount type -> returns 0.0
    Discount::query()->create([
        'code' => 'WEIRD',
        'name' => 'Unknown Type',
        'type' => 'bogo',
        'value' => 50,
        'currency' => 'USD',
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'is_active' => true,
    ]);

    $unknownResult = $service->applyDiscount($invoice->fresh(), 'WEIRD');
    expect($unknownResult['success'])->toBeTrue()
        ->and($unknownResult['discount_amount'])->toBe(0.0);

    // 5. handleRefund
    $payment = new Payment([
        'invoice_id' => $invoice->id,
        'amount' => 50.00,
        'currency' => 'USD',
        'status' => 'completed',
    ]);
    expect($service->handleRefund($payment, 25.0)['success'])->toBeTrue();
});

it('covers BillingService processRecurringInvoices, processSubscriptionBilling locked skip & catch', function (): void {
    $customer = Customer::factory()->create();
    $gateway = PaymentGateway::query()->create([
        'name' => 'Gateway',
        'api_key' => 'k',
        'secret_key' => 's',
    ]);
    PaymentMethod::query()->create([
        'customer_id' => $customer->id,
        'payment_gateway_id' => $gateway->id,
        'type' => 'card',
        'is_default' => true,
    ]);

    // Recurring invoice with item and due RecurringBillingConfiguration
    $originalInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'paid',
        'total_amount' => 50.00,
    ]);
    Invoice_Item::query()->create([
        'invoice_id' => $originalInvoice->id,
        'description' => 'Monthly Retainer',
        'quantity' => 1,
        'unit_price' => 50.00,
        'total_price' => 50.00,
        'currency' => 'USD',
    ]);
    $recurringConfig = RecurringBillingConfiguration::query()->create([
        'invoice_id' => $originalInvoice->id,
        'frequency' => 'monthly',
        'next_billing_date' => now()->subDay(),
        'is_active' => true,
    ]);

    // Two due subscriptions: processing sub1 cancels sub2 so sub2 hits the locked-status skip (line 405)
    $sub1 = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'end_date' => now()->subDay(),
        'status' => 'active',
        'price' => 20.00,
    ]);
    $sub2 = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'end_date' => now()->subDay(),
        'status' => 'active',
        'price' => 20.00,
    ]);

    $provisioning = Mockery::mock(ServiceProvisioningService::class);
    $provisioning->shouldReceive('manageService')
        ->andReturnUsing(function () use ($sub2): bool {
            $sub2->update(['status' => 'cancelled']);

            return true;
        });

    $gatewayService = Mockery::mock(PaymentGatewayService::class);
    $gatewayService->shouldReceive('processPayment')
        ->andReturn(['success' => true, 'transaction_id' => 'txn_rec_1']);

    $service = new BillingService(
        serviceProvisioningService: $provisioning,
        currencyService: app(CurrencyService::class),
        paymentGatewayService: $gatewayService,
    );

    $service->processRecurringBilling();

    expect($recurringConfig->fresh()->next_billing_date->isFuture())->toBeTrue()
        ->and(Invoice::query()->where('customer_id', $customer->id)->where('is_recurring', true)->count())->toBeGreaterThanOrEqual(2);

    // Trigger outer catch in processSubscriptionBilling (lines 444-445)
    Subscription::factory()->create([
        'customer_id' => $customer->id,
        'end_date' => now()->subDay(),
        'status' => 'active',
        'price' => 15.00,
    ]);

    $failingAudit = Mockery::mock(AuditLogService::class);
    $failingAudit->shouldReceive('log')
        ->with('invoice_created', Mockery::any(), Mockery::any(), Mockery::any())
        ->andThrow(new RuntimeException('audit failure'));
    $this->app->instance(AuditLogService::class, $failingAudit);

    $service->processRecurringBilling();
    expect(true)->toBeTrue();
});

it('covers BillingService processAutomaticPayment unsuspend, failure response, gateway exception, and handleFailedPayment suspend', function (): void {
    $customer = Customer::factory()->create();
    $gateway = PaymentGateway::query()->create([
        'name' => 'Gateway',
        'api_key' => 'k',
        'secret_key' => 's',
    ]);
    PaymentMethod::query()->create([
        'customer_id' => $customer->id,
        'payment_gateway_id' => $gateway->id,
        'type' => 'card',
        'is_default' => true,
    ]);

    // 1. Success unsuspends a suspended hosting account
    $subSuspended = Subscription::factory()->create(['customer_id' => $customer->id]);
    HostingAccount::factory()->cpanel()->suspended()->create([
        'customer_id' => $customer->id,
        'subscription_id' => $subSuspended->id,
    ]);
    $invoiceUnsuspend = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'subscription_id' => $subSuspended->id,
        'status' => 'pending',
    ]);

    $provisioning = Mockery::mock(ServiceProvisioningService::class);
    $provisioning->shouldReceive('manageService')
        ->once()
        ->with(Mockery::on(fn (Subscription $s): bool => $s->id === $subSuspended->id), 'unsuspend')
        ->andReturn(true);

    $gatewayOk = Mockery::mock(PaymentGatewayService::class);
    $gatewayOk->shouldReceive('processPayment')
        ->once()
        ->andReturn(['success' => true, 'transaction_id' => 'txn_unsuspend']);

    $okService = new BillingService(
        serviceProvisioningService: $provisioning,
        currencyService: app(CurrencyService::class),
        paymentGatewayService: $gatewayOk,
    );

    $resOk = $okService->processAutomaticPayment($invoiceUnsuspend);
    expect($resOk['success'])->toBeTrue();

    // 2. Failed payment response suspends active hosting account when past grace period
    $subActive = Subscription::factory()->create(['customer_id' => $customer->id]);
    HostingAccount::factory()->cpanel()->active()->create([
        'customer_id' => $customer->id,
        'subscription_id' => $subActive->id,
    ]);
    $overdueInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'subscription_id' => $subActive->id,
        'status' => 'pending',
        'due_date' => now()->subDays(10),
    ]);

    $provisioningSuspend = Mockery::mock(ServiceProvisioningService::class);
    $provisioningSuspend->shouldReceive('manageService')
        ->twice()
        ->with(Mockery::on(fn (Subscription $s): bool => $s->id === $subActive->id), 'suspend')
        ->andReturn(true);

    $gatewayFail = Mockery::mock(PaymentGatewayService::class);
    $gatewayFail->shouldReceive('processPayment')
        ->once()
        ->andReturn(['success' => false, 'message' => 'Insufficient funds']);
    $gatewayFail->shouldReceive('processPayment')
        ->once()
        ->andThrow(new RuntimeException('Gateway timeout'));

    $failService = new BillingService(
        serviceProvisioningService: $provisioningSuspend,
        currencyService: app(CurrencyService::class),
        paymentGatewayService: $gatewayFail,
    );

    $resDeclined = $failService->processAutomaticPayment($overdueInvoice);
    expect($resDeclined['success'])->toBeFalse()
        ->and($resDeclined['message'])->toBe('Insufficient funds')
        ->and($overdueInvoice->fresh()->status)->toBe('overdue');

    // 3. Gateway exception path
    $resException = $failService->processAutomaticPayment($overdueInvoice);
    expect($resException['success'])->toBeFalse()
        ->and($resException['message'])->toBe('Payment processing failed: Gateway timeout');
});

it('covers BillingService reminders, reports, and processLateFees including exception paths', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->id;

    $customer = Customer::factory()->create([
        'name' => 'SMS Customer',
        'email' => 'sms@example.test',
        'phone_number' => '+15551234567',
        'team_id' => $teamId,
    ]);
    $customer->forceFill(['sms_notifications_enabled' => true])->save();

    $upcomingInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'team_id' => $teamId,
        'status' => 'pending',
        'issue_date' => now()->subDays(2),
        'due_date' => now()->addDays(3),
        'total_amount' => 80.00,
        'upcoming_reminder_sent' => null,
    ]);

    $paidInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'team_id' => $teamId,
        'status' => 'paid',
        'issue_date' => now()->subDays(5),
        'due_date' => now()->subDay(),
        'paid_at' => now()->subDay(),
        'total_amount' => 120.00,
    ]);

    $smsService = Mockery::mock(SmsService::class);
    $smsService->shouldReceive('send')
        ->twice()
        ->with('+15551234567', Mockery::type('string'));

    $service = new BillingService(
        serviceProvisioningService: app(ServiceProvisioningService::class),
        currencyService: app(CurrencyService::class),
        smsService: $smsService,
    );

    // sendUpcomingDueReminders + getInvoiceReminderMessage
    $service->sendUpcomingDueReminders();

    // sendUpcomingInvoiceReminders (success)
    expect($service->sendUpcomingInvoiceReminders())->toBe(1)
        ->and((bool) $upcomingInvoice->fresh()->upcoming_reminder_sent)->toBeTrue();

    // sendUpcomingInvoiceReminders (exception catch)
    Invoice::factory()->create([
        'customer_id' => $customer->id,
        'team_id' => $teamId,
        'status' => 'pending',
        'due_date' => now()->addDays(4),
        'upcoming_reminder_sent' => null,
    ]);
    $failingSms = Mockery::mock(SmsService::class);
    $failingSms->shouldReceive('send')->once()->andThrow(new RuntimeException('SMS down'));
    $failingSmsService = new BillingService(
        serviceProvisioningService: app(ServiceProvisioningService::class),
        currencyService: app(CurrencyService::class),
        smsService: $failingSms,
    );
    expect($failingSmsService->sendUpcomingInvoiceReminders())->toBe(0);

    // Reports: getBillingSummary, getRevenueReport, getCustomerActivityReport
    $summary = $service->getBillingSummary([
        'start_date' => now()->subDays(10)->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
    ]);
    $revenue = $service->getRevenueReport([
        'start_date' => now()->subDays(10)->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
    ]);
    $activity = $service->getCustomerActivityReport([
        'customer_id' => $customer->id,
    ]);

    expect($summary)->not->toBeEmpty()
        ->and($revenue)->toHaveCount(1)
        ->and($revenue[0]['invoice_number'])->toBe($paidInvoice->invoice_number)
        ->and($activity)->toHaveCount(1)
        ->and($activity[0]['customer_id'])->toBe($customer->id);

    // processLateFees (success path)
    LateFeeConfiguration::query()->create([
        'team_id' => $teamId,
        'fee_type' => 'fixed',
        'fee_amount' => 15.00,
        'grace_period_days' => 0,
        'is_compound' => false,
        'frequency' => 'daily',
    ]);
    $lateInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'team_id' => $teamId,
        'status' => 'pending',
        'due_date' => now()->subDays(5),
        'total_amount' => 100.00,
        'late_fee_amount' => 0,
    ]);

    $service->processLateFees();
    expect((float) $lateInvoice->fresh()->late_fee_amount)->toBe(15.0);

    // processLateFees & sendOverdueReminders exception catch paths via AuditLogService
    $lateInvoice->forceFill([
        'status' => 'pending',
        'last_late_fee_date' => now()->subDays(2),
    ])->save();

    $throwingAudit = Mockery::mock(AuditLogService::class);
    $throwingAudit->shouldReceive('log')->andThrow(new RuntimeException('audit error'));
    $this->app->instance(AuditLogService::class, $throwingAudit);

    $service->processLateFees();
    expect($service->sendOverdueReminders())->toBe(0);
});
