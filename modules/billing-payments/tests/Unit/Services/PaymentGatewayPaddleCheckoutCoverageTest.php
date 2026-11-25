<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(DatabaseMigrations::class);

it('covers all Paddle checkout guard rails, claim race, success, and review fallback', function (): void {
    $paddleGateway = PaymentGateway::query()->create([
        'name' => 'Paddle',
        'api_key' => 'pk_paddle',
        'secret_key' => 'sk_paddle',
        'is_active' => true,
    ]);
    $otherGateway = PaymentGateway::query()->create([
        'name' => 'Stripe',
        'api_key' => 'pk_stripe',
        'secret_key' => 'sk_stripe',
        'is_active' => true,
    ]);
    $invoice = Invoice::factory()->create([
        'total_amount' => 40.00,
        'currency' => 'USD',
        'status' => 'pending',
    ]);
    $service = app(PaymentGatewayService::class);

    // 1. Unsaved payment
    $unsaved = new Payment([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
    ]);
    $unsaved->setRelation('paymentGateway', $paddleGateway);
    expect(fn () => $service->processPayment($unsaved))
        ->toThrow(Exception::class, 'Save the payment before starting Paddle checkout');

    // 2. Payment gateway changed in DB before checkout
    $changedGatewayPayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => null,
    ]);
    $changedGatewayPayment->forceFill(['status' => 'pending'])->save();
    $changedGatewayPayment->setRelation('paymentGateway', $paddleGateway);
    DB::table('payments')->where('id', $changedGatewayPayment->id)->update(['payment_gateway_id' => $otherGateway->id]);
    expect(fn () => $service->processPayment($changedGatewayPayment))
        ->toThrow(Exception::class, 'Payment gateway changed before checkout');

    // 3. Existing transaction_id returns cached checkout details
    $existingPayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => 'txn_existing123',
        'payment_method_details' => ['paddle_checkout_url' => 'https://checkout.paddle.test/existing'],
    ]);
    $existingPayment->forceFill(['status' => 'pending'])->save();
    expect($service->processPayment($existingPayment))->toBe([
        'id' => 'txn_existing123',
        'status' => 'pending',
        'checkout' => ['url' => 'https://checkout.paddle.test/existing'],
    ]);

    // 4. Non-pending status requires review
    $reviewPayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => null,
    ]);
    $reviewPayment->forceFill(['status' => 'review'])->save();
    expect(fn () => $service->processPayment($reviewPayment))
        ->toThrow(Exception::class, 'Paddle payment requires review before another checkout');

    // 5. Active DB transaction rejected
    $txPayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => null,
        'payment_method_details' => ['paddle_price_id' => 'pri_01abc'],
    ]);
    $txPayment->forceFill(['status' => 'pending'])->save();
    expect(fn () => DB::transaction(fn () => $service->processPayment($txPayment)))
        ->toThrow(Exception::class, 'Commit the payment before starting Paddle checkout');

    // 6. Missing / invalid paddle_price_id
    $noPricePayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => null,
        'payment_method_details' => ['paddle_price_id' => 'invalid_price'],
    ]);
    $noPricePayment->forceFill(['status' => 'pending'])->save();
    expect(fn () => $service->processPayment($noPricePayment))
        ->toThrow(Exception::class, 'A Paddle price ID is required for payment processing');

    // 7. Successful Paddle checkout with customer_id and quantity
    Http::fake([
        'https://api.paddle.com/transactions' => Http::sequence()
            ->push([
                'data' => [
                    'id' => 'txn_created123',
                    'status' => 'ready',
                    'checkout' => ['url' => 'https://checkout.paddle.test/created'],
                ],
            ], 200)
            ->push(['data' => ['id' => 'invalid_ref']], 200)
            ->push(['error' => 'Bad Gateway'], 502),
    ]);

    $successPayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => null,
        'payment_method_details' => [
            'paddle_price_id' => 'pri_01abc',
            'paddle_quantity' => 2,
            'paddle_customer_id' => 'ctm_01xyz',
        ],
    ]);
    $successPayment->forceFill(['status' => 'pending'])->save();

    $transaction = $service->processPayment($successPayment);
    expect($transaction['id'])->toBe('txn_created123')
        ->and($successPayment->fresh()->transaction_id)->toBe('txn_created123')
        ->and($successPayment->fresh()->status)->toBe('pending')
        ->and($successPayment->fresh()->payment_method_details['paddle_checkout_url'])->toBe('https://checkout.paddle.test/created');

    // 8. Invalid transaction reference returned -> marks status 'review'
    $badRefPayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => null,
        'payment_method_details' => ['paddle_price_id' => 'pri_01abc'],
    ]);
    $badRefPayment->forceFill(['status' => 'pending'])->save();

    expect(fn () => $service->processPayment($badRefPayment))
        ->toThrow(Exception::class, 'Paddle checkout requires review');
    expect($badRefPayment->fresh()->status)->toBe('review');

    // 9. HTTP error from Paddle -> marks status 'review'
    $httpErrPayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => null,
        'payment_method_details' => ['paddle_price_id' => 'pri_01abc'],
    ]);
    $httpErrPayment->forceFill(['status' => 'pending'])->save();

    expect(fn () => $service->processPayment($httpErrPayment))
        ->toThrow(Exception::class, 'Paddle checkout requires review');
    expect($httpErrPayment->fresh()->status)->toBe('review');

    // 10. Claim race ($claimed !== 1)
    $racedPayment = Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'payment_gateway_id' => $paddleGateway->id,
        'payment_method' => 'paddle',
        'amount' => 40.00,
        'currency' => 'USD',
        'transaction_id' => null,
        'payment_method_details' => ['paddle_price_id' => 'pri_01abc'],
    ]);
    $racedPayment->forceFill(['status' => 'pending'])->save();

    $racedId = $racedPayment->id;
    $fired = false;
    Payment::retrieved(function (Payment $model) use ($racedId, &$fired): void {
        if (! $fired && $model->id === $racedId) {
            $fired = true;
            DB::table('payments')->where('id', $racedId)->update(['status' => 'processing']);
        }
    });

    expect(fn () => $service->processPayment($racedPayment))
        ->toThrow(Exception::class, 'Paddle checkout has already started');
});
