<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Orders\Actions\AddChangeOrder;
use Liberu\Billing\Orders\Actions\CheckoutCart;
use Liberu\Billing\Orders\Actions\ConvertQuoteToOrder;
use Liberu\Billing\Orders\Actions\CreateCart;
use Liberu\Billing\Orders\Actions\CreateOrder;
use Liberu\Billing\Orders\Actions\CreateQuote;
use Liberu\Billing\Orders\Actions\ReviewFraud;
use Liberu\Billing\Orders\Actions\TransitionOrder;
use Liberu\Billing\Orders\Actions\TransitionQuote;
use Liberu\Billing\Orders\Enums\FraudReviewStatus;
use Liberu\Billing\Orders\Enums\OrderStatus;
use Liberu\Billing\Orders\Models\Order;
use Liberu\Billing\Orders\Policies\OrderPolicy;
use Liberu\Billing\Orders\Policies\QuotePolicy;
use Liberu\Billing\Orders\Queries\ListOrders;
use Liberu\Billing\Orders\Support\CustomerReference;

it('covers orders, quotes, and carts actions validation, edge cases, and happy paths', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();

    $customerId = DB::table('customers')->insertGetId([
        'team_id' => $team->id,
        'name' => 'Orders Customer',
        'email' => 'orders-customer@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // CustomerReference valid customer path
    expect(CustomerReference::assertBelongsToTeam(app('db'), $customerId, $team->id))->toBe($customerId);

    $createOrder = app(CreateOrder::class);
    $addChange = app(AddChangeOrder::class);
    $reviewFraud = app(ReviewFraud::class);
    $transitionOrder = app(TransitionOrder::class);
    $createQuote = app(CreateQuote::class);
    $transitionQuote = app(TransitionQuote::class);
    $convertQuote = app(ConvertQuoteToOrder::class);
    $createCart = app(CreateCart::class);
    $checkoutCart = app(CheckoutCart::class);

    // CreateCart validation + happy path
    expect(fn () => $createCart->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'items' => [],
    ]))->toThrow(\InvalidArgumentException::class);

    $cart = $createCart->execute([
        'team_id' => $team->id,
        'customer_id' => $customerId,
        'currency' => 'USD',
        'items' => [['sku' => 'PLAN-PRO', 'qty' => 1]],
    ]);
    expect($cart->status)->toBe('open');

    // CheckoutCart happy path + already checked-out cart throws
    $checkedOutOrder = $checkoutCart->execute($cart, ['subtotal_minor' => 2500]);
    expect($checkedOutOrder->total_minor)->toBe(2500)
        ->and($cart->fresh()->status)->toBe('checked_out');

    expect(fn () => $checkoutCart->execute($cart->refresh(), ['subtotal_minor' => 2500]))
        ->toThrow(\LogicException::class);

    // CreateQuote validation + happy path
    expect(fn () => $createQuote->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'total_minor' => -10,
        'items' => [['sku' => 'X']],
    ]))->toThrow(\InvalidArgumentException::class);

    $quote = $createQuote->execute([
        'team_id' => $team->id,
        'customer_id' => $customerId,
        'currency' => 'USD',
        'total_minor' => 3000,
        'items' => [['sku' => 'QUOTE-1', 'qty' => 1]],
    ]);

    // ConvertQuoteToOrder on non-accepted quote throws
    expect(fn () => $convertQuote->execute($quote))
        ->toThrow(\LogicException::class);

    // TransitionQuote invalid status throws
    expect(fn () => $transitionQuote->execute($quote, 'invalid-status'))
        ->toThrow(\InvalidArgumentException::class);

    $acceptedQuote = $transitionQuote->execute($quote, 'accepted');
    $convertedOrder = $convertQuote->execute($acceptedQuote);
    expect($convertedOrder->quote_id)->toBe($quote->id);

    // AddChangeOrder empty reason throws
    expect(fn () => $addChange->execute($convertedOrder, ['reason' => '   ']))
        ->toThrow(\InvalidArgumentException::class);

    // ReviewFraud happy path
    $reviewed = $reviewFraud->execute($convertedOrder, FraudReviewStatus::Cleared);
    expect($reviewed->fraud_status)->toBe(FraudReviewStatus::Cleared);

    // TransitionOrder: blocked order cannot be approved (in-memory check)
    $blockedOrder = $createOrder->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'subtotal_minor' => 1000,
        'fraud_review_required' => true,
    ]);
    $blockedOrder = $reviewFraud->execute($blockedOrder, FraudReviewStatus::Blocked);
    expect(fn () => $transitionOrder->execute($blockedOrder, OrderStatus::Approved))
        ->toThrow(\LogicException::class);

    // TransitionOrder: stale in-memory order whose DB status became Completed
    $staleCompleted = $createOrder->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'subtotal_minor' => 1000,
    ]);
    Order::query()->whereKey($staleCompleted->id)->update(['status' => OrderStatus::Completed->value]);
    expect(fn () => $transitionOrder->execute($staleCompleted, OrderStatus::Cancelled))
        ->toThrow(\LogicException::class);

    // TransitionOrder: stale in-memory order whose DB fraud_status became Blocked
    $staleBlocked = $createOrder->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'subtotal_minor' => 1000,
        'fraud_review_required' => true,
    ]);
    Order::query()->whereKey($staleBlocked->id)->update(['fraud_status' => FraudReviewStatus::Blocked->value]);
    expect(fn () => $transitionOrder->execute($staleBlocked, OrderStatus::Approved))
        ->toThrow(\LogicException::class);

    // ListOrders query (with teamId and with null)
    expect(app(ListOrders::class)->execute($team->id, 10)->total())->toBeGreaterThanOrEqual(1);
    expect(app(ListOrders::class)->execute(null, 10)->total())->toBe(0);
});

it('covers order and quote policies and customer reference missing table guard', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);

    $readOnlyUser = User::factory()->withPersonalTeam()->create();
    $readOnlyUser->switchTeam($team);

    Sanctum::actingAs($user, ['billing.orders.read', 'billing.orders.write']);
    Sanctum::actingAs($readOnlyUser, ['billing.orders.read']);

    $order = app(CreateOrder::class)->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'subtotal_minor' => 1500,
    ]);
    $quote = app(CreateQuote::class)->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'total_minor' => 1500,
        'items' => [['sku' => 'Q1']],
    ]);

    $orderPolicy = new OrderPolicy();
    expect($orderPolicy->viewAny($user))->toBeTrue()
        ->and($orderPolicy->view($user, $order))->toBeTrue()
        ->and($orderPolicy->create($user))->toBeTrue()
        ->and($orderPolicy->update($user, $order))->toBeTrue()
        ->and($orderPolicy->update($readOnlyUser, $order))->toBeFalse();

    $quotePolicy = new QuotePolicy();
    expect($quotePolicy->viewAny($user))->toBeTrue()
        ->and($quotePolicy->view($user, $quote))->toBeTrue()
        ->and($quotePolicy->create($user))->toBeTrue()
        ->and($quotePolicy->update($user, $quote))->toBeTrue()
        ->and($quotePolicy->create($readOnlyUser))->toBeFalse()
        ->and($quotePolicy->update($readOnlyUser, $quote))->toBeFalse();

    // CustomerReference when customers table does not exist
    Schema::shouldReceive('hasTable')->with('customers')->once()->andReturn(false);
    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), 1, $team->id))
        ->toThrow(\InvalidArgumentException::class);
});
