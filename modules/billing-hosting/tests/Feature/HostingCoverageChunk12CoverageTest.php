<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\HostingFulfillment;
use App\Models\HostingServer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Products_Service;
use App\Models\Subscription;
use App\Models\User;
use App\Policies\HostingAccountPolicy;
use App\Services\ServiceProvisioningService;
use Spatie\Permission\Models\Permission;

it('skips pending fulfillments when the invoice is unpaid or belongs to another customer', function (): void {
    $product = Products_Service::factory()->create(['type' => 'hosting']);

    $unpaidSubscription = Subscription::factory()->create(['product_service_id' => $product->id]);
    $unpaidInvoice = Invoice::factory()->create([
        'subscription_id' => $unpaidSubscription->id,
        'customer_id' => $unpaidSubscription->customer_id,
        'status' => 'pending',
    ]);
    $unpaidOrder = Order::query()->create([
        'subscription_id' => $unpaidSubscription->id,
        'customer_id' => $unpaidSubscription->customer_id,
        'invoice_id' => $unpaidInvoice->id,
    ]);
    $unpaidFulfillment = HostingFulfillment::query()->create([
        'order_id' => $unpaidOrder->id,
        'subscription_id' => $unpaidSubscription->id,
        'invoice_id' => $unpaidInvoice->id,
        'status' => 'pending',
    ]);

    $mismatchedSubscription = Subscription::factory()->create(['product_service_id' => $product->id]);
    $otherCustomer = Customer::factory()->create();
    $mismatchedInvoice = Invoice::factory()->create([
        'subscription_id' => $mismatchedSubscription->id,
        'customer_id' => $otherCustomer->id,
        'status' => 'paid',
    ]);
    $mismatchedOrder = Order::query()->create([
        'subscription_id' => $mismatchedSubscription->id,
        'customer_id' => $mismatchedSubscription->customer_id,
        'invoice_id' => $mismatchedInvoice->id,
    ]);
    $mismatchedFulfillment = HostingFulfillment::query()->create([
        'order_id' => $mismatchedOrder->id,
        'subscription_id' => $mismatchedSubscription->id,
        'invoice_id' => $mismatchedInvoice->id,
        'status' => 'pending',
    ]);

    $this->mock(ServiceProvisioningService::class)->shouldNotReceive('provisionService');

    $this->artisan('hosting:dispatch-fulfillments')->assertSuccessful();

    expect($unpaidFulfillment->fresh()->status)->toBe('pending')
        ->and($unpaidFulfillment->fresh()->attempts)->toBe(0)
        ->and($mismatchedFulfillment->fresh()->status)->toBe('pending')
        ->and($mismatchedFulfillment->fresh()->attempts)->toBe(0);
});

it('provisions hosting and completes fulfillment and order for paid invoices', function (): void {
    $product = Products_Service::factory()->create(['type' => 'hosting']);
    $subscription = Subscription::factory()->create(['product_service_id' => $product->id]);
    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
        'status' => 'paid',
    ]);
    $order = Order::query()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
        'invoice_id' => $invoice->id,
        'status' => 'pending',
    ]);
    $fulfillment = HostingFulfillment::query()->create([
        'order_id' => $order->id,
        'subscription_id' => $subscription->id,
        'invoice_id' => $invoice->id,
        'status' => 'pending',
    ]);

    $activeAccount = HostingAccount::factory()->active()->make([
        'customer_id' => $subscription->customer_id,
        'subscription_id' => $subscription->id,
    ]);

    $this->mock(ServiceProvisioningService::class)
        ->shouldReceive('provisionService')
        ->once()
        ->withArgs(fn (Subscription $sub): bool => $sub->is($subscription))
        ->andReturn($activeAccount);

    $this->artisan('hosting:dispatch-fulfillments')->assertSuccessful();

    $fulfillment->refresh();
    $order->refresh();

    expect($fulfillment->status)->toBe('completed')
        ->and($fulfillment->attempts)->toBe(1)
        ->and($fulfillment->completed_at)->not->toBeNull()
        ->and($fulfillment->last_error)->toBeNull()
        ->and($order->status)->toBe('completed');
});

it('reuses an existing active hosting account without reprovisioning', function (): void {
    $product = Products_Service::factory()->create(['type' => 'hosting']);
    $subscription = Subscription::factory()->create(['product_service_id' => $product->id]);
    HostingAccount::factory()->active()->create([
        'customer_id' => $subscription->customer_id,
        'subscription_id' => $subscription->id,
    ]);
    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
        'status' => 'paid',
    ]);
    $order = Order::query()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
        'invoice_id' => $invoice->id,
        'status' => 'pending',
    ]);
    $fulfillment = HostingFulfillment::query()->create([
        'order_id' => $order->id,
        'subscription_id' => $subscription->id,
        'invoice_id' => $invoice->id,
        'status' => 'pending',
    ]);

    $this->mock(ServiceProvisioningService::class)->shouldNotReceive('provisionService');

    $this->artisan('hosting:dispatch-fulfillments')->assertSuccessful();

    expect($fulfillment->fresh()->status)->toBe('completed')
        ->and($order->fresh()->status)->toBe('completed');
});

it('marks fulfillment as failed when provisioning returns a non-active account or throws', function (): void {
    $product = Products_Service::factory()->create(['type' => 'hosting']);
    $subscription = Subscription::factory()->create(['product_service_id' => $product->id]);
    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
        'status' => 'paid',
    ]);
    $order = Order::query()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
        'invoice_id' => $invoice->id,
        'status' => 'pending',
    ]);
    $fulfillment = HostingFulfillment::query()->create([
        'order_id' => $order->id,
        'subscription_id' => $subscription->id,
        'invoice_id' => $invoice->id,
        'status' => 'pending',
    ]);

    $pendingAccount = HostingAccount::factory()->pending()->make([
        'customer_id' => $subscription->customer_id,
        'subscription_id' => $subscription->id,
    ]);

    $this->mock(ServiceProvisioningService::class)
        ->shouldReceive('provisionService')
        ->once()
        ->andReturn($pendingAccount);

    $this->artisan('hosting:dispatch-fulfillments')
        ->expectsOutput('Hosting provisioning requires review.')
        ->assertSuccessful();

    $fulfillment->refresh();

    expect($fulfillment->status)->toBe('failed')
        ->and($fulfillment->attempts)->toBe(1)
        ->and($fulfillment->last_error)->toBe('Hosting provisioning requires review.');
});

it('resolves HostingFulfillment relationships to order, subscription, and invoice', function (): void {
    $subscription = Subscription::factory()->create();
    $invoice = Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
    ]);
    $order = Order::query()->create([
        'subscription_id' => $subscription->id,
        'customer_id' => $subscription->customer_id,
        'invoice_id' => $invoice->id,
    ]);
    $fulfillment = HostingFulfillment::query()->create([
        'order_id' => $order->id,
        'subscription_id' => $subscription->id,
        'invoice_id' => $invoice->id,
        'status' => 'pending',
    ]);

    expect($fulfillment->order->is($order))->toBeTrue()
        ->and($fulfillment->subscription->is($subscription))->toBeTrue()
        ->and($fulfillment->invoice->is($invoice))->toBeTrue();
});

it('evaluates HostingAccount domain and addons helpers', function (): void {
    $withDomainAndAddons = HostingAccount::factory()->make([
        'domain' => 'example.com',
        'addons' => ['ssl', 'backups'],
    ]);
    $withoutDomainOrAddons = HostingAccount::factory()->make([
        'domain' => '',
        'addons' => null,
    ]);

    expect($withDomainAndAddons->hasDomain())->toBeTrue()
        ->and($withDomainAndAddons->getAddons())->toBe(['ssl', 'backups'])
        ->and($withoutDomainOrAddons->hasDomain())->toBeFalse()
        ->and($withoutDomainOrAddons->getAddons())->toBe([]);
});

it('evaluates HostingServer relationships, capacity helpers, and usage percentage', function (): void {
    $server = HostingServer::factory()->create([
        'max_accounts' => 100,
        'active_accounts' => 25,
    ]);
    $account = HostingAccount::factory()->create([
        'hosting_server_id' => $server->id,
    ]);

    expect($server->hostingAccounts)->toHaveCount(1)
        ->and($server->hostingAccounts->first()->is($account))->toBeTrue()
        ->and($server->isAtCapacity())->toBeFalse()
        ->and($server->hasCapacity())->toBeTrue()
        ->and($server->getUsagePercentage())->toEqual(25);

    $fullServer = HostingServer::factory()->create([
        'max_accounts' => 10,
        'active_accounts' => 10,
    ]);

    expect($fullServer->isAtCapacity())->toBeTrue()
        ->and($fullServer->hasCapacity())->toBeFalse()
        ->and($fullServer->getUsagePercentage())->toEqual(100);

    $unlimitedServer = HostingServer::factory()->create([
        'max_accounts' => 0,
        'active_accounts' => 5,
    ]);

    expect($unlimitedServer->isAtCapacity())->toBeFalse()
        ->and($unlimitedServer->hasCapacity())->toBeTrue()
        ->and($unlimitedServer->getUsagePercentage())->toBe(0);
});

it('enforces all HostingAccountPolicy permissions', function (): void {
    $policy = new HostingAccountPolicy();
    $user = User::factory()->create();
    $account = HostingAccount::factory()->create();

    $modelMethods = [
        'view' => 'view_hosting::account',
        'update' => 'update_hosting::account',
        'delete' => 'delete_hosting::account',
        'forceDelete' => 'force_delete_hosting::account',
        'restore' => 'restore_hosting::account',
        'replicate' => 'replicate_hosting::account',
    ];

    $collectionMethods = [
        'viewAny' => 'view_any_hosting::account',
        'create' => 'create_hosting::account',
        'deleteAny' => 'delete_any_hosting::account',
        'forceDeleteAny' => 'force_delete_any_hosting::account',
        'restoreAny' => 'restore_any_hosting::account',
        'reorder' => 'reorder_hosting::account',
    ];

    foreach ($modelMethods as $method => $permission) {
        expect($policy->{$method}($user, $account))->toBeFalse();
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        expect($policy->{$method}($user, $account))->toBeTrue();
    }

    foreach ($collectionMethods as $method => $permission) {
        expect($policy->{$method}($user))->toBeFalse();
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        expect($policy->{$method}($user))->toBeTrue();
    }
});
