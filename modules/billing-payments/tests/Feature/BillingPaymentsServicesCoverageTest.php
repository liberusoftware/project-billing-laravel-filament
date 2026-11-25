<?php

declare(strict_types=1);

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentHistory;
use App\Models\PaymentPlan;
use App\Services\BillingService;
use App\Services\HostingFulfillmentService;
use App\Services\PartialPaymentService;
use App\Services\PaymentGatewayService;
use App\Services\PaymentPlanService;
use App\Services\PaymentReconciliationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Square\Legacy\Exceptions\ApiException;
use Square\Legacy\Http\HttpRequest;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;

it('rolls back and returns failure when partial payment gateway processing fails', function (): void {
    $gateway = PaymentGateway::query()->create([
        'name' => 'Test Gateway',
        'api_key' => 'pk_test',
        'secret_key' => 'sk_test',
        'is_active' => true,
    ]);
    $invoice = Invoice::factory()->create([
        'total_amount' => 100.00,
        'status' => 'pending',
    ]);

    $this->mock(PaymentGatewayService::class, function ($mock): void {
        $mock->shouldReceive('processPayment')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Card declined']);
    });

    $service = new PartialPaymentService(app(PaymentGatewayService::class));
    $result = $service->processPartialPayment($invoice, 40.00, $gateway->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Partial payment failed: Card declined')
        ->and(Payment::query()->count())->toBe(0);
});

it('completes payment plans at final installment and computes weekly and quarterly due dates', function (): void {
    $billingService = Mockery::mock(BillingService::class);
    $service = new PaymentPlanService($billingService);

    $weeklyInvoice = Invoice::factory()->create(['total_amount' => 100.00, 'currency' => 'USD']);
    $start = Carbon::parse('2026-01-01 00:00:00');
    $weeklyPlan = PaymentPlan::query()->create([
        'invoice_id' => $weeklyInvoice->id,
        'total_installments' => 4,
        'installment_amount' => 25.00,
        'frequency' => 'weekly',
        'start_date' => $start,
        'next_due_date' => $start,
        'status' => 'active',
    ]);

    $service->createInstallmentInvoice($weeklyPlan);
    expect($weeklyPlan->fresh()->next_due_date->toDateString())->toBe('2026-01-08');

    $quarterlyInvoice = Invoice::factory()->create(['total_amount' => 300.00, 'currency' => 'USD']);
    $quarterlyPlan = PaymentPlan::query()->create([
        'invoice_id' => $quarterlyInvoice->id,
        'total_installments' => 3,
        'installment_amount' => 100.00,
        'frequency' => 'quarterly',
        'start_date' => $start,
        'next_due_date' => $start,
        'status' => 'active',
    ]);

    $service->createInstallmentInvoice($quarterlyPlan);
    expect($quarterlyPlan->fresh()->next_due_date->toDateString())->toBe('2026-04-01');

    // Plan with 1 total installment transitions to completed during processPaymentPlans()
    $completingInvoice = Invoice::factory()->create(['total_amount' => 50.00, 'currency' => 'USD']);
    $completingPlan = PaymentPlan::query()->create([
        'invoice_id' => $completingInvoice->id,
        'total_installments' => 1,
        'installment_amount' => 50.00,
        'frequency' => 'monthly',
        'start_date' => now()->subMonth(),
        'next_due_date' => now()->subDay(),
        'status' => 'active',
    ]);

    $service->processPaymentPlans();

    expect($completingPlan->fresh()->status)->toBe('completed');
});

it('reconciles by customer and amount fallback, marks unmatched payments, and handles reconciliation exceptions', function (): void {
    $customer = Customer::factory()->create();
    $olderInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'total_amount' => 75.00,
        'currency' => 'USD',
        'status' => 'pending',
        'due_date' => now()->subDays(5),
    ]);
    $newerInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'total_amount' => 75.00,
        'currency' => 'USD',
        'status' => 'pending',
        'due_date' => now()->addDays(5),
    ]);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'invoice_id' => $newerInvoice->id,
        'amount' => 75.00,
        'currency' => 'USD',
        'transaction_id' => 'txn_fallback_1',
    ]);
    $payment->forceFill(['status' => 'completed'])->save();

    // Clear invoice_id in memory (synced so update() does not attempt writing NULL) to exercise fallback lookup
    $payment->setRawAttributes(array_merge($payment->getAttributes(), ['invoice_id' => null]), true);

    $service = app(PaymentReconciliationService::class);
    expect($service->reconcilePayment($payment))->toBeTrue()
        ->and($olderInvoice->fresh()->status)->toBe('paid')
        ->and($newerInvoice->fresh()->status)->toBe('pending');

    // Unmatched payment (no pending invoice with matching amount)
    $unmatchedPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'invoice_id' => $newerInvoice->id,
        'amount' => 999.99,
        'currency' => 'USD',
        'transaction_id' => 'txn_unmatched_1',
    ]);
    $unmatchedPayment->forceFill(['status' => 'completed'])->save();
    $unmatchedPayment->setRawAttributes(array_merge($unmatchedPayment->getAttributes(), ['invoice_id' => null]), true);

    expect($service->reconcilePayment($unmatchedPayment))->toBeFalse();
    $this->assertDatabaseHas(PaymentHistory::class, [
        'payment_id' => $unmatchedPayment->id,
        'invoice_id' => null,
        'status' => 'unmatched',
    ]);

    // Exception during reconciliation is caught and returns false
    $failingInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'total_amount' => 45.00,
        'currency' => 'USD',
        'status' => 'pending',
    ]);
    $failingPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'invoice_id' => $failingInvoice->id,
        'amount' => 45.00,
        'currency' => 'USD',
        'transaction_id' => 'txn_exc_1',
    ]);
    $failingPayment->forceFill(['status' => 'completed'])->save();

    $this->mock(HostingFulfillmentService::class, function ($mock): void {
        $mock->shouldReceive('prepare')->once()->andThrow(new Exception('Fulfillment error'));
    });

    expect($service->reconcilePayment($failingPayment))->toBeFalse();
});

it('flags currency, status, refunded, and customer discrepancies during reconciliation', function (): void {
    $customerA = Customer::factory()->create();
    $customerB = Customer::factory()->create();
    $service = app(PaymentReconciliationService::class);

    // Currency mismatch
    $invCurrency = Invoice::factory()->create(['customer_id' => $customerA->id, 'total_amount' => 50.00, 'currency' => 'EUR', 'status' => 'pending']);
    $payCurrency = Payment::factory()->create(['customer_id' => $customerA->id, 'invoice_id' => $invCurrency->id, 'amount' => 50.00, 'currency' => 'USD', 'transaction_id' => 'txn_cur']);
    $payCurrency->forceFill(['status' => 'completed'])->save();
    expect($service->reconcilePayment($payCurrency))->toBeFalse();

    // Incomplete payment / blank transaction_id
    $invStatus = Invoice::factory()->create(['customer_id' => $customerA->id, 'total_amount' => 50.00, 'currency' => 'USD', 'status' => 'pending']);
    $payStatus = Payment::factory()->create(['customer_id' => $customerA->id, 'invoice_id' => $invStatus->id, 'amount' => 50.00, 'currency' => 'USD', 'transaction_id' => null]);
    $payStatus->forceFill(['status' => 'pending'])->save();
    expect($service->reconcilePayment($payStatus))->toBeFalse();

    // Refunded payment
    $invRefunded = Invoice::factory()->create(['customer_id' => $customerA->id, 'total_amount' => 50.00, 'currency' => 'USD', 'status' => 'pending']);
    $payRefunded = Payment::factory()->create(['customer_id' => $customerA->id, 'invoice_id' => $invRefunded->id, 'amount' => 50.00, 'currency' => 'USD', 'transaction_id' => 'txn_ref']);
    $payRefunded->forceFill(['status' => 'completed', 'refunded_amount' => 10.00, 'refund_status' => 'partial'])->save();
    expect($service->reconcilePayment($payRefunded))->toBeFalse();

    // Different customer
    $invCustomer = Invoice::factory()->create(['customer_id' => $customerA->id, 'total_amount' => 50.00, 'currency' => 'USD', 'status' => 'pending']);
    $payCustomer = Payment::factory()->create(['customer_id' => $customerB->id, 'invoice_id' => $invCustomer->id, 'amount' => 50.00, 'currency' => 'USD', 'transaction_id' => 'txn_cust']);
    $payCustomer->forceFill(['status' => 'completed'])->save();
    expect($service->reconcilePayment($payCustomer))->toBeFalse();
});

it('processes PayPal, Authorize.net, Google Pay failure, and unsupported gateway payments and refunds', function (): void {
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

    $service = app(PaymentGatewayService::class);
    $delayProp = new ReflectionProperty(PaymentGatewayService::class, 'retryDelay');
    $delayProp->setValue($service, 0);

    // PayPal payment + refund
    $paypalGateway = PaymentGateway::query()->create(['name' => 'PayPal', 'api_key' => 'pk', 'secret_key' => 'sk', 'is_active' => true]);
    $paypalPayment = Payment::factory()->create(['payment_gateway_id' => $paypalGateway->id, 'payment_method' => 'paypal', 'currency' => 'USD']);
    expect($service->processPayment($paypalPayment))->toBeNull()
        ->and($service->refundPayment($paypalPayment, 10.00))->toMatchArray(['success' => true]);

    // Authorize.net payment + refund
    $authGateway = PaymentGateway::query()->create(['name' => 'Authorize.net', 'api_key' => 'pk', 'secret_key' => 'sk', 'is_active' => true]);
    $authPayment = Payment::factory()->create(['payment_gateway_id' => $authGateway->id, 'payment_method' => 'credit_card', 'currency' => 'USD']);
    expect($service->processPayment($authPayment))->toBeNull()
        ->and($service->refundPayment($authPayment, 10.00))->toMatchArray(['success' => true]);

    // Google Pay missing token -> fails & catches exception
    $gpGateway = PaymentGateway::query()->create(['name' => 'Google Pay', 'api_key' => 'pk', 'secret_key' => 'sk', 'is_active' => true]);
    $gpPayment = Payment::factory()->create(['payment_gateway_id' => $gpGateway->id, 'payment_method' => 'google_pay', 'currency' => 'USD']);
    $gpPayment->forceFill(['google_pay_token' => null])->save();
    expect(fn () => $service->processPayment($gpPayment))
        ->toThrow(Exception::class, 'Google Pay payment failed: Google Pay token is required');

    // Unsupported gateway payment + refund retry exhaustion
    $unknownGateway = PaymentGateway::query()->create(['name' => 'UnsupportedGW', 'api_key' => 'pk', 'secret_key' => 'sk', 'is_active' => true]);
    $unknownPayment = Payment::factory()->create(['payment_gateway_id' => $unknownGateway->id, 'payment_method' => 'credit_card', 'currency' => 'USD']);
    expect(fn () => $service->processPayment($unknownPayment))
        ->toThrow(Exception::class, 'Unsupported payment gateway')
        ->and(fn () => $service->refundPayment($unknownPayment, 10.00))
        ->toThrow(Exception::class, 'Unsupported payment gateway for refunds');
});

it('handles Stripe missing token, CardException on charge, and ApiErrorException on refund', function (): void {
    config(['services.stripe.api_version' => '2026-08-26.dahlia']);
    $previousClient = ApiRequestor::httpClient();
    $previousKey = Stripe::getApiKey();
    $previousVersion = Stripe::getApiVersion();

    $gateway = PaymentGateway::query()->create(['name' => 'Stripe', 'api_key' => 'pk_test', 'secret_key' => 'sk_test', 'is_active' => true]);
    $service = app(PaymentGatewayService::class);

    // Missing Stripe token
    $missingTokenPayment = Payment::factory()->create([
        'payment_gateway_id' => $gateway->id,
        'payment_method' => 'credit_card',
        'amount' => 20.00,
        'currency' => 'USD',
    ]);
    $missingTokenPayment->forceFill(['stripe_token' => null])->save();
    expect(fn () => $service->processPayment($missingTokenPayment))
        ->toThrow(Exception::class, 'Stripe token is required for payment processing');

    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->with('post', 'https://api.stripe.com/v1/charges', Mockery::any(), Mockery::any(), false, 'v1', Mockery::any())
        ->andReturn(['{"error":{"type":"card_error","code":"card_declined","message":"Your card was declined."}}', 402, []]);
    $http->shouldReceive('request')
        ->once()
        ->with('post', 'https://api.stripe.com/v1/refunds', Mockery::any(), Mockery::any(), false, 'v1', Mockery::any())
        ->andReturn(['{"error":{"type":"api_error","message":"Stripe internal error"}}', 500, []]);
    ApiRequestor::setHttpClient($http);

    try {
        $cardPayment = Payment::factory()->create([
            'payment_gateway_id' => $gateway->id,
            'payment_method' => 'credit_card',
            'amount' => 20.00,
            'currency' => 'USD',
            'transaction_id' => 'ch_refund_err',
        ]);
        $cardPayment->forceFill(['stripe_token' => 'tok_declined'])->save();

        expect(fn () => $service->processPayment($cardPayment))
            ->toThrow(Exception::class, 'Payment failed: Your card was declined.');

        $refundResult = $service->refundPayment($cardPayment, 5.00);
        expect($refundResult)->toBe([
            'success' => false,
            'message' => 'Stripe internal error',
        ]);
    } finally {
        ApiRequestor::setHttpClient($previousClient);
        Stripe::setApiKey($previousKey);
        Stripe::setApiVersion($previousVersion);
    }
});

it('handles Paddle full refund, partial refund, validation errors, and HTTP failure', function (): void {
    $gateway = PaymentGateway::query()->create(['name' => 'Paddle', 'api_key' => 'pk', 'secret_key' => 'pdl_secret', 'is_active' => true]);
    $service = app(PaymentGatewayService::class);
    $delayProp = new ReflectionProperty(PaymentGatewayService::class, 'retryDelay');
    $delayProp->setValue($service, 0);

    // Empty transaction_id
    $emptyTxnPayment = Payment::factory()->create([
        'payment_gateway_id' => $gateway->id,
        'payment_method' => 'paddle',
        'amount' => 50.00,
        'currency' => 'USD',
        'transaction_id' => '',
    ]);
    expect(fn () => $service->refundPayment($emptyTxnPayment, 50.00))
        ->toThrow(Exception::class, 'A Paddle transaction ID is required for refunds');

    // Partial refund missing paddle_transaction_item_id
    $partialMissingItem = Payment::factory()->create([
        'payment_gateway_id' => $gateway->id,
        'payment_method' => 'paddle',
        'amount' => 50.00,
        'currency' => 'USD',
        'transaction_id' => 'txn_paddle_1',
        'payment_method_details' => [],
    ]);
    expect(fn () => $service->refundPayment($partialMissingItem, 20.00))
        ->toThrow(Exception::class, 'A Paddle transaction item ID is required for partial refunds');

    // Full and partial Paddle refunds + HTTP error
    Http::fake([
        'https://api.paddle.com/adjustments' => Http::sequence()
            ->push(['data' => ['id' => 'adj_full', 'status' => 'approved']], 200)
            ->push(['data' => ['id' => 'adj_partial', 'status' => 'approved']], 200)
            ->push(['error' => 'Unauthorized'], 401)
            ->push(['error' => 'Unauthorized'], 401)
            ->push(['error' => 'Unauthorized'], 401),
    ]);

    $paddlePayment = Payment::factory()->create([
        'payment_gateway_id' => $gateway->id,
        'payment_method' => 'paddle',
        'amount' => 50.00,
        'currency' => 'USD',
        'transaction_id' => 'txn_paddle_2',
        'payment_method_details' => ['paddle_transaction_item_id' => 'txnitm_123'],
    ]);

    expect($service->refundPayment($paddlePayment, 50.00))->toBe(['id' => 'adj_full', 'status' => 'approved'])
        ->and($service->refundPayment($paddlePayment, 20.00))->toBe(['id' => 'adj_partial', 'status' => 'approved'])
        ->and(fn () => $service->refundPayment($paddlePayment, 50.00))
        ->toThrow(Exception::class, 'Paddle API returned HTTP 401');
});

it('handles Square payment success, API error response, and ApiException', function (): void {
    $gateway = PaymentGateway::query()->create(['name' => 'Square', 'api_key' => 'sq_pk', 'secret_key' => 'sq_sk', 'is_active' => true]);
    $service = app(PaymentGatewayService::class);

    $squarePaymentObj = new class()
    {
        public function getId(): string
        {
            return 'sq_pay_123';
        }
    };
    $successResult = new class($squarePaymentObj)
    {
        public function __construct(private object $payment) {}

        public function getPayment(): object
        {
            return $this->payment;
        }
    };
    $successResponse = new class($successResult)
    {
        public function __construct(private object $result) {}

        public function isSuccess(): bool
        {
            return true;
        }

        public function getResult(): object
        {
            return $this->result;
        }
    };

    $errorItem = new class()
    {
        public function getDetail(): string
        {
            return 'Card declined by Square';
        }
    };
    $errorResponse = new class($errorItem)
    {
        public function __construct(private object $error) {}

        public function isSuccess(): bool
        {
            return false;
        }

        public function getErrors(): array
        {
            return [$this->error];
        }
    };

    $paymentsApi = Mockery::mock();
    $paymentsApi->shouldReceive('createPayment')->once()->andReturn($successResponse);
    $paymentsApi->shouldReceive('createPayment')->once()->andReturn($errorResponse);
    $paymentsApi->shouldReceive('createPayment')->once()->andThrow(
        new ApiException('Square transport error', Mockery::mock(HttpRequest::class), null)
    );

    $squareClientMock = Mockery::mock('overload:Square\Legacy\SquareClient');
    $squareClientMock->shouldReceive('getPaymentsApi')->times(3)->andReturn($paymentsApi);

    $payment = Payment::factory()->create([
        'payment_gateway_id' => $gateway->id,
        'payment_method' => 'square',
        'amount' => 30.00,
        'currency' => 'USD',
    ]);
    $payment->forceFill(['square_token' => 'cnon:card-nonce-ok'])->save();

    $result = $service->processPayment($payment);
    expect($result)->toBe($squarePaymentObj);

    expect(fn () => $service->processPayment($payment))
        ->toThrow(Exception::class, 'Card declined by Square');

    expect(fn () => $service->processPayment($payment))
        ->toThrow(Exception::class, 'Square payment failed: Square transport error');
});
