<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Orders\Models\Quote;

it('manages orders, quotes, carts, fraud reviews, and change orders through the orders API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    Sanctum::actingAs($user, ['billing.orders.read', 'billing.orders.write']);

    // Create order
    $orderId = $this->postJson(route('billing.orders.orders.store'), [
        'currency' => 'USD',
        'subtotal_minor' => 5000,
        'discount_minor' => 500,
        'tax_minor' => 250,
        'fraud_review_required' => true,
        'metadata' => ['source' => 'api'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-orders')
        ->assertJsonPath('data.attributes.total_minor', 4750)
        ->json('data.id');

    // List orders
    $this->getJson(route('billing.orders.orders.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $orderId)
        ->assertJsonPath('meta.current_page', 1);

    // Review fraud
    $this->postJson(route('billing.orders.fraud-review', ['order' => $orderId]), [
        'fraud_status' => 'cleared',
    ])->assertOk()
        ->assertJsonPath('data.attributes.fraud_status', 'cleared');

    // Add change order
    $this->postJson(route('billing.orders.change-orders.store', ['order' => $orderId]), [
        'reason' => 'Add extra seats',
        'amount_minor' => 1000,
    ])->assertOk()
        ->assertJsonPath('data.attributes.change_orders.0.reason', 'Add extra seats');

    // Create quote, transition to accepted, and convert to order
    $quoteId = $this->postJson(route('billing.orders.quotes.store'), [
        'currency' => 'USD',
        'total_minor' => 3000,
        'items' => [['sku' => 'PRO-PLAN', 'qty' => 1]],
        'valid_until' => now()->addDays(7)->toIso8601String(),
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-order-quotes')
        ->json('data.id');

    $this->patchJson(route('billing.orders.quotes.lifecycle', ['quote' => $quoteId]), [
        'status' => 'accepted',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'accepted');

    $this->postJson(route('billing.orders.quotes.convert', ['quote' => $quoteId]))
        ->assertCreated()
        ->assertJsonPath('data.type', 'billing-orders');

    // Create cart and checkout
    $cartId = $this->postJson(route('billing.orders.carts.store'), [
        'currency' => 'USD',
        'items' => [['sku' => 'ADDON-1', 'qty' => 2]],
        'expires_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-order-carts')
        ->json('data.id');

    $this->postJson(route('billing.orders.carts.checkout', ['cart' => $cartId]), [
        'subtotal_minor' => 2000,
        'discount_minor' => 0,
        'tax_minor' => 100,
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-orders')
        ->assertJsonPath('data.attributes.total_minor', 2100);
});

it('expires overdue quotes through the orders API', function (): void {
    Gate::before(fn (): true => true);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    Sanctum::actingAs($user, ['billing.orders.read', 'billing.orders.write']);

    Quote::query()->create([
        'team_id' => $team->id,
        'quote_number' => 'QUO-EXPIRED-1',
        'currency' => 'USD',
        'total_minor' => 1000,
        'items' => [['sku' => 'OLD']],
        'status' => 'sent',
        'valid_until' => now()->subDay(),
    ]);

    $this->postJson(route('billing.orders.quotes.expire'))
        ->assertOk()
        ->assertJsonPath('expired', 1);
});
