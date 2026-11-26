<?php

declare(strict_types=1);

use App\Models\Affiliate;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentHistory;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Policies\PaymentPolicy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\RolesPermissions\Models\Permission;

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('rejects paddle settlement webhooks containing duplicate timestamps in the signature header', function (): void {
    $invoice = Invoice::factory()->create(['status' => 'pending', 'total_amount' => 25, 'currency' => 'USD']);
    $gateway = PaymentGateway::query()->create([
        'name' => 'Paddle',
        'is_active' => true,
        'api_key' => 'test-key',
        'secret_key' => 'test-secret',
    ]);

    $payment = new Payment();
    $payment->forceFill([
        'invoice_id' => $invoice->id,
        'customer_id' => $invoice->customer_id,
        'team_id' => $invoice->team_id,
        'payment_gateway_id' => $gateway->id,
        'amount' => 25,
        'currency' => 'USD',
        'payment_date' => now(),
        'payment_method' => 'paddle',
        'status' => 'pending',
        'transaction_id' => 'txn_dupts',
    ])->save();

    config()->set('services.paddle.webhook_secret', 'notification-secret');
    config()->set('services.paddle.webhook_gateway_id', $gateway->id);

    $payload = [
        'event_type' => 'transaction.completed',
        'data' => [
            'id' => 'txn_dupts',
            'status' => 'completed',
            'currency_code' => 'USD',
            'custom_data' => ['billing_payment_id' => $payment->id],
            'details' => ['totals' => ['grand_total' => '2500']],
        ],
    ];

    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.':'.$body, 'notification-secret');

    $this->call('POST', '/api/webhooks/paddle/settlement', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_PADDLE_SIGNATURE' => 'ts='.$timestamp.';ts='.$timestamp.';h1='.$signature,
    ], $body)->assertUnauthorized();

    expect($payment->fresh()->status)->toBe('pending');
});

it('covers payment badges, relationships, formatting, and refund processing', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $currency = Currency::query()->firstOrCreate(
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
    $affiliate = Affiliate::query()->create([
        'user_id' => $user->id,
        'code' => 'AFF-PAY-001',
        'commission_rate' => 10.0,
        'status' => 'active',
    ]);

    if (! Schema::hasColumn('payments', 'affiliate_id')) {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('affiliate_id')->nullable();
        });
    }

    $payment = Payment::factory()->create([
        'amount' => 100.00,
        'currency' => 'USD',
        'affiliate_id' => $affiliate->id,
    ]);
    $payment->forceFill([
        'refund_status' => 'none',
        'refunded_amount' => null,
    ])->save();

    // Relationships
    expect($payment->currency()->first()?->is($currency))->toBeTrue()
        ->and($payment->affiliate?->is($affiliate))->toBeTrue();

    // Reconciliation status badge arms
    $reconciliationBadges = [
        'reconciled' => '<span class="badge badge-success">Reconciled</span>',
        'unmatched' => '<span class="badge badge-warning">Unmatched</span>',
        'discrepancy' => '<span class="badge badge-danger">Discrepancy</span>',
        'failed' => '<span class="badge badge-danger">Failed</span>',
        null => '<span class="badge badge-secondary">Pending</span>',
    ];
    foreach ($reconciliationBadges as $status => $expectedBadge) {
        $payment->reconciliation_status = $status;
        expect($payment->reconciliation_status_badge)->toBe($expectedBadge);
    }

    // Formatting and remaining refundable amount
    expect($payment->getRemainingRefundableAmount())->toEqual(100.0)
        ->and($payment->getFormattedAmount())->toBe('100.00 USD')
        ->and($payment->getFormattedRefundedAmount())->toBe('0.00 USD');

    // Refund exceeding remaining amount throws
    expect(fn () => $payment->processRefund(150.00, 'Over-refund'))
        ->toThrow(Exception::class, 'Refund amount exceeds remaining refundable amount');

    // Partial refund
    expect($payment->processRefund(40.00, 'Partial reason'))->toBeTrue();
    $payment->refresh();
    expect($payment->refunded_amount)->toEqual(40.0)
        ->and($payment->refund_status)->toBe('partial')
        ->and($payment->refund_reason)->toBe('Partial reason')
        ->and($payment->getRemainingRefundableAmount())->toEqual(60.0)
        ->and($payment->getFormattedRefundedAmount())->toBe('40.00 USD');

    // Full refund completes remaining balance
    expect($payment->processRefund(60.00, 'Final reason'))->toBeTrue();
    $payment->refresh();
    expect($payment->refunded_amount)->toEqual(100.0)
        ->and($payment->refund_status)->toBe('full')
        ->and($payment->refund_reason)->toBe('Final reason');

    // Not eligible for refund when already full
    expect(fn () => $payment->processRefund(1.00))
        ->toThrow(Exception::class, 'This payment is not eligible for refund');

    // Refund status badge arms
    $refundBadges = [
        'none' => '<span class="badge badge-danger">No Refund</span>',
        'partial' => '<span class="badge badge-warning">Partial Refund</span>',
        'full' => '<span class="badge badge-success">Full Refund</span>',
        'other' => '<span class="badge badge-secondary">Unknown</span>',
    ];
    foreach ($refundBadges as $status => $expectedBadge) {
        $payment->refund_status = $status;
        expect($payment->refund_status_badge)->toBe($expectedBadge);
    }
});

it('covers payment gateway, payment history, and payment method relationships', function (): void {
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id]);
    $gateway = PaymentGateway::query()->create([
        'name' => 'Stripe',
        'api_key' => 'pk_test_rel',
        'secret_key' => 'sk_test_rel',
        'is_active' => true,
    ]);

    $payment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $gateway->id,
        'customer_id' => $customer->id,
        'amount' => 50.00,
        'currency' => 'USD',
    ]);

    $history = PaymentHistory::query()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'amount' => 50.00,
        'currency' => 'USD',
        'payment_method' => 'credit_card',
        'transaction_id' => 'txn_hist_1',
        'status' => 'reconciled',
        'notes' => 'Matched',
    ]);

    $method = PaymentMethod::query()->create([
        'customer_id' => $customer->id,
        'payment_gateway_id' => $gateway->id,
        'type' => 'card',
        'token' => 'pm_tok_123',
        'is_default' => true,
    ]);

    expect($gateway->payments->pluck('id')->all())->toContain($payment->id)
        ->and($history->payment?->is($payment))->toBeTrue()
        ->and($history->invoice?->is($invoice))->toBeTrue()
        ->and($history->customer?->is($customer))->toBeTrue()
        ->and($method->customer->is($customer))->toBeTrue()
        ->and($method->paymentGateway->is($gateway))->toBeTrue();
});

dataset('payment_policy_abilities', [
    'viewAny' => ['viewAny', 'view_any_refund', false],
    'view' => ['view', 'view_refund', true],
    'create' => ['create', 'create_refund', false],
    'update' => ['update', 'update_refund', true],
    'delete' => ['delete', 'delete_refund', true],
    'deleteAny' => ['deleteAny', 'delete_any_refund', false],
    'forceDelete' => ['forceDelete', 'force_delete_refund', true],
    'forceDeleteAny' => ['forceDeleteAny', 'force_delete_any_refund', false],
    'restore' => ['restore', 'restore_refund', true],
    'restoreAny' => ['restoreAny', 'restore_any_refund', false],
    'replicate' => ['replicate', 'replicate_refund', true],
    'reorder' => ['reorder', 'reorder_refund', false],
]);

it('denies payment policy ability without permission and allows it when granted', function (
    string $method,
    string $permission,
    bool $requiresModel,
): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    setPermissionsTeamId($team->id);

    $payment = Payment::factory()->create(['team_id' => $team->id]);
    $policy = new PaymentPolicy();
    $args = $requiresModel ? [$user, $payment] : [$user];

    expect($policy->{$method}(...$args))->toBeFalse();

    Permission::findOrCreate($permission, 'web');
    $user->givePermissionTo($permission);

    expect($policy->{$method}(...$args))->toBeTrue();
})->with('payment_policy_abilities');
