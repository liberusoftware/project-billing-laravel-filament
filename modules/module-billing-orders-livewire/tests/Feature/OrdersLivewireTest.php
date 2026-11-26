<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Orders\Actions\CreateQuote;
use Liberu\Billing\Orders\Enums\FraudReviewStatus;
use Liberu\Billing\Orders\Enums\OrderStatus;
use Liberu\Billing\Orders\Livewire\Components\OrderList;
use Liberu\Billing\Orders\Livewire\Components\OrderSupport;
use Liberu\Billing\Orders\Livewire\Components\QuoteList;
use Liberu\Billing\Orders\Models\Cart;
use Liberu\Billing\Orders\Models\Order;
use Livewire\Livewire;

it('renders and manages OrderList and QuoteList Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.orders.read', 'billing.orders.write']);

    // OrderList: checkout, reviewFraud, addChangeOrder
    $orderList = Livewire::test(OrderList::class)
        ->assertOk()
        ->set('currency', 'USD')
        ->set('subtotalMinor', 2000)
        ->call('checkout')
        ->assertHasNoErrors();

    $order = Order::query()->where('team_id', $team->id)->latest('id')->firstOrFail();

    $orderList
        ->set('fraudStatus', 'cleared')
        ->call('reviewFraud', $order->id)
        ->assertHasNoErrors()
        ->set('changeReason', 'Extra bandwidth')
        ->set('changeAmountMinor', 400)
        ->call('addChangeOrder', $order->id)
        ->assertHasNoErrors();

    expect($order->fresh()->fraud_status)->toBe(FraudReviewStatus::Cleared)
        ->and($order->fresh()->change_orders)->toHaveCount(1);

    // QuoteList: transition and convert
    $quote = app(CreateQuote::class)->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'total_minor' => 2500,
        'items' => [['sku' => 'LW-QUOTE', 'qty' => 1]],
    ]);

    Livewire::test(QuoteList::class)
        ->assertOk()
        ->set('selectedQuoteId', $quote->id)
        ->set('status', 'accepted')
        ->call('transitionQuote')
        ->assertHasNoErrors()
        ->call('convert')
        ->assertHasNoErrors();

    expect($quote->fresh()->status)->toBe('accepted')
        ->and(Order::query()->where('quote_id', $quote->id)->exists())->toBeTrue();
});

it('renders and manages OrderSupport Livewire component', function (): void {
    Gate::before(fn (): true => true);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.orders.read', 'billing.orders.write']);

    $support = Livewire::test(OrderSupport::class)
        ->assertOk()
        ->set('currency', 'USD')
        ->set('quoteTotalMinor', 1800)
        ->set('quoteItems', '[{"sku":"SUP-Q1","qty":1}]')
        ->call('createQuote')
        ->assertHasNoErrors()
        ->set('cartItems', '[{"sku":"SUP-C1","qty":2}]')
        ->call('createCart')
        ->assertHasNoErrors();

    // Non-array JSON string triggers decodeItems InvalidArgumentException
    expect(fn () => Livewire::test(OrderSupport::class)
        ->set('currency', 'USD')
        ->set('quoteTotalMinor', 1800)
        ->set('quoteItems', '"not-an-array"')
        ->call('createQuote'))
        ->toThrow(InvalidArgumentException::class);

    $cart = Cart::query()->where('team_id', $team->id)->latest('id')->firstOrFail();

    $support
        ->set('selectedCartId', $cart->id)
        ->set('cartSubtotalMinor', 1200)
        ->call('checkoutCart')
        ->assertHasNoErrors();

    $order = Order::query()->where('team_id', $team->id)->latest('id')->firstOrFail();

    $support
        ->set('selectedOrderId', $order->id)
        ->set('orderStatus', 'completed')
        ->call('transitionOrder')
        ->assertHasNoErrors();

    expect($cart->fresh()->status)->toBe('checked_out')
        ->and($order->fresh()->status)->toBe(OrderStatus::Completed);
});
