<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Events\InvoiceStatusChanged;
use App\Jobs\ProcessSubscriptionBilling;
use App\Listeners\QueuePaidSubscriptionChanges;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Products_Service;
use App\Models\RecurringBillingConfiguration;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Policies\SubscriptionPolicy;
use App\Services\BillingService;
use App\Services\ServiceProvisioningService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

it('rejects custom billing cycles with missing or non-positive custom period days', function (): void {
    expect(fn () => BillingCycle::Custom->advance(Carbon::parse('2026-06-01'), null))
        ->toThrow(InvalidArgumentException::class, 'A custom billing cycle requires custom_period_days of at least 1.');

    expect(fn () => BillingCycle::Custom->advance(Carbon::parse('2026-06-01'), 0))
        ->toThrow(InvalidArgumentException::class, 'A custom billing cycle requires custom_period_days of at least 1.');
});

it('resolves the invoice relationship on RecurringBillingConfiguration', function (): void {
    $invoice = Invoice::factory()->create();
    $config = RecurringBillingConfiguration::query()->create([
        'invoice_id' => $invoice->id,
        'frequency' => 'monthly',
        'billing_day' => 15,
        'next_billing_date' => now()->addMonth()->toDateString(),
        'is_active' => true,
    ]);

    expect($config->invoice())->toBeInstanceOf(BelongsTo::class)
        ->and($config->invoice?->id)->toBe($invoice->id);
});

it('formats SubscriptionPlan price with two decimals and currency', function (): void {
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Enterprise',
        'code' => 'enterprise-plan',
        'price' => 1234.5,
        'currency' => 'EUR',
        'is_active' => true,
        'trial_days' => 14,
    ]);

    expect($plan->formatted_price)->toBe('1,234.50 EUR');
});

it('covers Subscription relationships, lifecycle methods, and billing eligibility checks', function (): void {
    $subscription = Subscription::factory()->create([
        'status' => 'active',
        'auto_renew' => true,
        'renewal_period' => 'monthly',
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
        'last_billed_at' => null,
    ]);

    expect($subscription->suspensions())->toBeInstanceOf(HasMany::class)
        ->and($subscription->ispService())->toBeInstanceOf(HasOne::class)
        ->and($subscription->voipAccount())->toBeInstanceOf(HasOne::class)
        ->and($subscription->resellerDelegation())->toBeInstanceOf(HasOne::class)
        ->and($subscription->isActive())->toBeTrue()
        ->and($subscription->needsBilling())->toBeTrue();

    // Non-recurring cycle never needs billing once billed
    $subscription->forceFill([
        'last_billed_at' => now()->subMonths(2),
        'renewal_period' => 'one-time',
    ])->save();
    expect($subscription->needsBilling())->toBeFalse();

    // Recurring cycle past due and active needs billing
    $subscription->forceFill([
        'last_billed_at' => now()->subMonths(2),
        'renewal_period' => 'monthly',
        'status' => 'active',
        'end_date' => now()->addMonth(),
    ])->save();
    expect($subscription->needsBilling())->toBeTrue();

    // Recurring cycle recently billed does not need billing
    $subscription->forceFill([
        'last_billed_at' => now(),
    ])->save();
    expect($subscription->needsBilling())->toBeFalse();

    // resume() returns false when not suspended
    expect($subscription->resume())->toBeFalse();

    // suspend() transitions to suspended, resume() transitions back to active
    expect($subscription->suspend())->toBeTrue()
        ->and($subscription->fresh()->status)->toBe('suspended')
        ->and($subscription->isActive())->toBeFalse()
        ->and($subscription->resume())->toBeTrue()
        ->and($subscription->fresh()->status)->toBe('active');

    // cancel() disables auto_renew and marks cancelled
    expect($subscription->cancel())->toBeTrue()
        ->and($subscription->fresh()->status)->toBe('cancelled')
        ->and($subscription->fresh()->auto_renew)->toBeFalse();
});

it('authorizes all SubscriptionPolicy abilities based on user permissions', function (): void {
    $policy = new SubscriptionPolicy();
    $subscription = Subscription::factory()->create();
    $user = User::factory()->create();

    $abilityMap = [
        'viewAny' => ['permission' => 'view_any_subscription', 'withModel' => false],
        'view' => ['permission' => 'view_subscription', 'withModel' => true],
        'create' => ['permission' => 'create_subscription', 'withModel' => false],
        'update' => ['permission' => 'update_subscription', 'withModel' => true],
        'delete' => ['permission' => 'delete_subscription', 'withModel' => true],
        'deleteAny' => ['permission' => 'delete_any_subscription', 'withModel' => false],
        'forceDelete' => ['permission' => 'force_delete_subscription', 'withModel' => true],
        'forceDeleteAny' => ['permission' => 'force_delete_any_subscription', 'withModel' => false],
        'restore' => ['permission' => 'restore_subscription', 'withModel' => true],
        'restoreAny' => ['permission' => 'restore_any_subscription', 'withModel' => false],
        'replicate' => ['permission' => 'replicate_subscription', 'withModel' => true],
        'reorder' => ['permission' => 'reorder_subscription', 'withModel' => false],
    ];

    foreach ($abilityMap as $method => $config) {
        $denied = $config['withModel']
            ? $policy->{$method}($user, $subscription)
            : $policy->{$method}($user);

        expect($denied)->toBeFalse();

        Permission::findOrCreate($config['permission'], 'web');
        $user->givePermissionTo($config['permission']);

        $allowed = $config['withModel']
            ? $policy->{$method}($user, $subscription)
            : $policy->{$method}($user);

        expect($allowed)->toBeTrue();
    }
});

it('ignores non-paid invoice status changes in QueuePaidSubscriptionChanges listener', function (): void {
    $subscription = Subscription::factory()->create();
    $target = Products_Service::factory()->create(['type' => $subscription->productService->type]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $subscription->customer_id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
    ]);
    $change = SubscriptionChange::query()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
        'invoice_id' => $invoice->id,
        'target_product_service_id' => $target->id,
        'type' => 'upgrade',
        'status' => 'pending',
    ]);

    $listener = new QueuePaidSubscriptionChanges();
    $listener->handle(new InvoiceStatusChanged($invoice, 'pending'));
    $listener->handle(new InvoiceStatusChanged($invoice, 'paid'));

    expect($change->fresh()->status)->toBe('pending');
});

it('bills and renews only active auto-renew subscriptions that need billing in ProcessSubscriptionBilling job', function (): void {
    $dueSubscription = Subscription::factory()->create([
        'status' => 'active',
        'auto_renew' => true,
        'renewal_period' => 'monthly',
        'start_date' => now()->subMonth(),
        'end_date' => now()->addDays(5),
        'last_billed_at' => null,
    ]);

    $notDueSubscription = Subscription::factory()->create([
        'status' => 'active',
        'auto_renew' => true,
        'renewal_period' => 'monthly',
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'last_billed_at' => now(),
    ]);

    $generatedInvoice = Invoice::factory()->create([
        'customer_id' => $dueSubscription->customer_id,
        'subscription_id' => $dueSubscription->id,
    ]);

    $billingService = Mockery::mock(BillingService::class);
    $billingService->shouldReceive('generateInvoice')
        ->once()
        ->withArgs(fn (Subscription $sub): bool => $sub->id === $dueSubscription->id)
        ->andReturn($generatedInvoice);
    $billingService->shouldReceive('processAutomaticPayment')
        ->once()
        ->withArgs(fn (Invoice $inv): bool => $inv->id === $generatedInvoice->id)
        ->andReturn(['status' => 'paid']);

    (new ProcessSubscriptionBilling())->handle($billingService);

    expect($dueSubscription->fresh()->last_billed_at)->not->toBeNull()
        ->and($notDueSubscription->fresh()->end_date->toDateString())
        ->toBe($notDueSubscription->end_date->toDateString());
});

it('handles unsupported upgrades, provider rejections, and ineligible or rejected scheduled changes in ProcessSubscriptionChanges', function (): void {
    // 1. Unsupported change type / mismatched product type -> hits line 39 and catch block (47-49)
    $productA = Products_Service::factory()->create(['type' => 'hosting', 'base_price' => 50.00]);
    $productDifferentType = Products_Service::factory()->create(['type' => 'domain', 'base_price' => 80.00]);

    $subUnsupported = Subscription::factory()->create([
        'product_service_id' => $productA->id,
        'status' => 'active',
        'end_date' => now()->addMonth(),
    ]);
    $invoiceUnsupported = Invoice::factory()->create([
        'customer_id' => $subUnsupported->customer_id,
        'subscription_id' => $subUnsupported->id,
        'status' => 'paid',
    ]);
    $changeUnsupported = SubscriptionChange::query()->create([
        'subscription_id' => $subUnsupported->id,
        'customer_id' => $subUnsupported->customer_id,
        'invoice_id' => $invoiceUnsupported->id,
        'target_product_service_id' => $productDifferentType->id,
        'type' => 'upgrade',
        'status' => 'pending',
    ]);

    // 2. Provider rejects upgrade -> hits line 43 and catch block (47-49)
    $productUpgradeTarget = Products_Service::factory()->create(['type' => 'hosting', 'base_price' => 100.00]);
    $subRejectedUpgrade = Subscription::factory()->create([
        'product_service_id' => $productA->id,
        'status' => 'active',
        'end_date' => now()->addMonth(),
    ]);
    $invoiceRejected = Invoice::factory()->create([
        'customer_id' => $subRejectedUpgrade->customer_id,
        'subscription_id' => $subRejectedUpgrade->id,
        'status' => 'paid',
    ]);
    $changeRejected = SubscriptionChange::query()->create([
        'subscription_id' => $subRejectedUpgrade->id,
        'customer_id' => $subRejectedUpgrade->customer_id,
        'invoice_id' => $invoiceRejected->id,
        'target_product_service_id' => $productUpgradeTarget->id,
        'type' => 'upgrade',
        'status' => 'queued',
    ]);

    // 3. Scheduled change skipped because subscription is no longer active -> hits line 59
    Subscription::factory()->create([
        'product_service_id' => $productA->id,
        'status' => 'suspended',
        'end_date' => now()->subDay(),
        'scheduled_change' => ['type' => 'cancel'],
    ]);

    // 4. Scheduled downgrade no longer eligible (target price >= current price) -> hits line 72
    $subIneligibleDowngrade = Subscription::factory()->create([
        'product_service_id' => $productA->id,
        'status' => 'active',
        'end_date' => now()->subDay(),
        'scheduled_change' => ['type' => 'downgrade', 'product_service_id' => $productUpgradeTarget->id],
    ]);

    // 5. Scheduled downgrade eligible, but provider rejects it -> hits line 75
    $productDowngradeTarget = Products_Service::factory()->create([
        'type' => 'hosting',
        'base_price' => 20.00,
    ]);
    $subRejectedDowngrade = Subscription::factory()->create([
        'product_service_id' => $productA->id,
        'status' => 'active',
        'end_date' => now()->subDay(),
        'scheduled_change' => ['type' => 'downgrade', 'product_service_id' => $productDowngradeTarget->id],
    ]);

    $mock = $this->mock(ServiceProvisioningService::class);
    $mock->shouldReceive('manageService')
        ->once()
        ->withArgs(fn (Subscription $sub, string $action): bool => $sub->id === $subRejectedUpgrade->id && $action === 'upgrade')
        ->andReturn(false);
    $mock->shouldReceive('manageService')
        ->once()
        ->withArgs(fn (Subscription $sub, string $action): bool => $sub->id === $subRejectedDowngrade->id && $action === 'downgrade')
        ->andReturn(false);

    $this->artisan('subscriptions:process-changes')->assertSuccessful();

    expect($changeUnsupported->fresh()->status)->toBe('failed')
        ->and($changeUnsupported->fresh()->last_error)->toBe('Unsupported subscription change.')
        ->and($changeRejected->fresh()->status)->toBe('failed')
        ->and($changeRejected->fresh()->last_error)->toBe('The provider rejected the subscription change.')
        ->and($subIneligibleDowngrade->fresh()->product_service_id)->toBe($productA->id)
        ->and($subRejectedDowngrade->fresh()->product_service_id)->toBe($productA->id);
});

it('handles full CRUD, filtering, cancel, and renew via SubscriptionController API endpoints', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->id;
    $user->forceFill(['current_team_id' => $teamId])->save();

    foreach ([
        'view_any_subscription',
        'view_subscription',
        'create_subscription',
        'update_subscription',
        'delete_subscription',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    Sanctum::actingAs($user, ['subscriptions:read', 'subscriptions:write']);

    $customer = Customer::factory()->create();
    $customer->forceFill(['team_id' => $teamId])->save();

    $product = Products_Service::factory()->create();
    $product->forceFill(['team_id' => $teamId])->save();

    // store
    $storeResponse = $this->postJson('/api/subscriptions', [
        'customer_id' => $customer->id,
        'product_service_id' => $product->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-08-01',
        'renewal_period' => 'monthly',
        'price' => 49.99,
        'currency' => 'USD',
        'auto_renew' => true,
    ]);

    $storeResponse->assertCreated()
        ->assertJsonPath('customer_id', $customer->id)
        ->assertJsonPath('team_id', $teamId);

    $subscriptionId = (int) $storeResponse->json('id');

    // ensure status is active for index filter
    Subscription::query()->whereKey($subscriptionId)->update(['status' => 'active']);

    // index with customer_id and status filters
    $this->getJson('/api/subscriptions?customer_id='.$customer->id.'&status=active')
        ->assertOk()
        ->assertJsonPath('data.0.id', $subscriptionId);

    // show
    $this->getJson('/api/subscriptions/'.$subscriptionId)
        ->assertOk()
        ->assertJsonPath('id', $subscriptionId);

    // update
    $this->putJson('/api/subscriptions/'.$subscriptionId, [
        'end_date' => '2026-09-01',
        'renewal_period' => 'quarterly',
        'price' => 129.99,
        'currency' => 'EUR',
        'auto_renew' => false,
        'status' => 'suspended',
    ])
        ->assertOk()
        ->assertJsonPath('status', 'suspended')
        ->assertJsonPath('currency', 'EUR');

    // cancel
    $this->postJson('/api/subscriptions/'.$subscriptionId.'/cancel')
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    // renew
    $this->postJson('/api/subscriptions/'.$subscriptionId.'/renew')
        ->assertOk()
        ->assertJsonPath('status', 'active');

    // destroy
    $this->deleteJson('/api/subscriptions/'.$subscriptionId)
        ->assertNoContent();

    expect(Subscription::query()->find($subscriptionId))->toBeNull();
});
