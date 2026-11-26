<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Orders\Enums\FraudReviewStatus;
use Liberu\Billing\Orders\Enums\OrderStatus;
use Liberu\Billing\Orders\Filament\OrdersFilamentPlugin;
use Liberu\Billing\Orders\Filament\Resources\CartResource;
use Liberu\Billing\Orders\Filament\Resources\CartResource\Pages\CreateCart;
use Liberu\Billing\Orders\Filament\Resources\CartResource\Pages\ListCarts;
use Liberu\Billing\Orders\Filament\Resources\OrderResource;
use Liberu\Billing\Orders\Filament\Resources\OrderResource\Pages\CreateOrder;
use Liberu\Billing\Orders\Filament\Resources\OrderResource\Pages\ListOrders;
use Liberu\Billing\Orders\Filament\Resources\QuoteResource;
use Liberu\Billing\Orders\Filament\Resources\QuoteResource\Pages\CreateQuote;
use Liberu\Billing\Orders\Filament\Resources\QuoteResource\Pages\ListQuotes;
use Liberu\Billing\Orders\Models\Cart;
use Liberu\Billing\Orders\Models\Order;
use Liberu\Billing\Orders\Models\Quote;
use Livewire\Livewire;

it('manages orders, quotes, and carts through Filament resources', function (): void {
    Gate::before(fn (): true => true);

    $actor = User::factory()->withPersonalTeam()->create();
    $team = $actor->ownedTeams()->sole();
    $actor->switchTeam($team);
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.orders.read', 'billing.orders.write']);

    foreach ([Order::class, Quote::class, Cart::class] as $modelClass) {
        $modelClass::resolveRelationUsing('team', fn ($model) => $model->belongsTo(Team::class, 'team_id'));
    }

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);
    OrdersFilamentPlugin::make()->boot($panel);

    try {
        // OrderResource: create + list + review_fraud + change_order + transition
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'currency' => 'USD',
                'subtotal_minor' => 2500,
                'discount_minor' => 200,
                'tax_minor' => 300,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::query()->where('team_id', $team->id)->latest('id')->firstOrFail();

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->callTableAction('review_fraud', $order, data: ['fraud_status' => 'cleared'])
            ->callTableAction('change_order', $order, data: ['reason' => 'Scope update', 'amount_minor' => 500])
            ->callTableAction('transition', $order, data: ['status' => 'completed']);

        expect($order->fresh()->fraud_status)->toBe(FraudReviewStatus::Cleared)
            ->and($order->fresh()->status)->toBe(OrderStatus::Completed);

        // QuoteResource: create + invalid non-array JSON items + list + transition + convert
        Livewire::test(CreateQuote::class)
            ->fillForm([
                'currency' => 'USD',
                'total_minor' => 3000,
                'items' => '[{"sku":"Q-1","qty":1}]',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(fn () => Livewire::test(CreateQuote::class)
            ->fillForm([
                'currency' => 'USD',
                'total_minor' => 3000,
                'items' => '"not-an-array"',
            ])
            ->call('create'))
            ->toThrow(InvalidArgumentException::class);

        $quote = Quote::query()->where('team_id', $team->id)->latest('id')->firstOrFail();

        Livewire::test(ListQuotes::class)
            ->assertCanSeeTableRecords([$quote])
            ->callTableAction('transition', $quote, data: ['status' => 'accepted'])
            ->callTableAction('convert', $quote->refresh());

        expect($quote->fresh()->status)->toBe('accepted')
            ->and(Order::query()->where('quote_id', $quote->id)->exists())->toBeTrue();

        // CartResource: create + invalid non-array JSON items + list + checkout
        Livewire::test(CreateCart::class)
            ->fillForm([
                'currency' => 'USD',
                'items' => '[{"sku":"C-1","qty":2}]',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(fn () => Livewire::test(CreateCart::class)
            ->fillForm([
                'currency' => 'USD',
                'items' => '"not-an-array"',
            ])
            ->call('create'))
            ->toThrow(InvalidArgumentException::class);

        $cart = Cart::query()->where('team_id', $team->id)->latest('id')->firstOrFail();

        Livewire::test(ListCarts::class)
            ->assertCanSeeTableRecords([$cart])
            ->callTableAction('checkout', $cart, data: ['subtotal_minor' => 1500]);

        expect($cart->fresh()->status)->toBe('checked_out');
    } finally {
        Filament::setTenant(null);
    }
});

it('scopes Filament orders queries when no current team is active', function (): void {
    Order::query()->create([
        'team_id' => 999,
        'order_number' => 'ORD-OTHER-1',
        'currency' => 'USD',
        'subtotal_minor' => 1000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 1000,
        'status' => OrderStatus::Draft,
        'fraud_status' => FraudReviewStatus::NotRequired,
    ]);
    Quote::query()->create([
        'team_id' => 999,
        'quote_number' => 'QUO-OTHER-1',
        'currency' => 'USD',
        'total_minor' => 1000,
        'items' => [['sku' => 'A']],
        'status' => 'draft',
    ]);
    Cart::query()->create([
        'team_id' => 999,
        'currency' => 'USD',
        'items' => [['sku' => 'B']],
        'status' => 'open',
    ]);

    $this->actingAs(User::factory()->create(), 'web');

    expect(OrderResource::getEloquentQuery()->count())->toBe(0)
        ->and(QuoteResource::getEloquentQuery()->count())->toBe(0)
        ->and(CartResource::getEloquentQuery()->count())->toBe(0);
});
