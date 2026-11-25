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
use App\Services\ControlPanels\CpanelClient;
use App\Services\ControlPanels\DirectAdminClient;
use App\Services\ControlPanels\LiberuControlPanelClient;
use App\Services\ControlPanels\PleskClient;
use App\Services\ControlPanels\VirtualminClient;
use App\Services\HostingFulfillmentService;
use App\Services\HostingService;
use App\Services\PricingService;

it('skips orders with missing subscriptions, mismatched customers, or non-hosting products during fulfillment preparation', function (): void {
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();

    $hostingProduct = Products_Service::factory()->create(['type' => 'hosting']);
    $domainProduct = Products_Service::factory()->create(['type' => 'domain']);

    $validSubscription = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'product_service_id' => $hostingProduct->id,
    ]);
    $nonHostingSubscription = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'product_service_id' => $domainProduct->id,
    ]);
    $foreignSubscription = Subscription::factory()->create([
        'customer_id' => $otherCustomer->id,
        'product_service_id' => $hostingProduct->id,
    ]);

    $invoice = Invoice::factory()->create([
        'subscription_id' => $validSubscription->id,
        'customer_id' => $customer->id,
        'status' => 'paid',
    ]);

    Order::query()->create([
        'subscription_id' => null,
        'customer_id' => $customer->id,
        'invoice_id' => $invoice->id,
    ]);
    Order::query()->create([
        'subscription_id' => $nonHostingSubscription->id,
        'customer_id' => $customer->id,
        'invoice_id' => $invoice->id,
    ]);
    Order::query()->create([
        'subscription_id' => $foreignSubscription->id,
        'customer_id' => $customer->id,
        'invoice_id' => $invoice->id,
    ]);
    $validOrder = Order::query()->create([
        'subscription_id' => $validSubscription->id,
        'customer_id' => $customer->id,
        'invoice_id' => $invoice->id,
    ]);

    app(HostingFulfillmentService::class)->prepare($invoice);

    expect(HostingFulfillment::query()->count())->toBe(1)
        ->and(HostingFulfillment::query()->first()->order_id)->toBe($validOrder->id);
});

it('throws when provisioning a hosting account and no available server exists', function (): void {
    HostingServer::factory()->cpanel()->inactive()->create();
    HostingServer::factory()->cpanel()->atCapacity()->create();

    $account = HostingAccount::factory()->make([
        'status' => 'pending',
        'hosting_server_id' => null,
    ]);
    $account->save();

    $product = Products_Service::factory()->create(['name' => 'starter-plan']);

    $hostingService = new HostingService(
        Mockery::mock(CpanelClient::class),
        Mockery::mock(PleskClient::class),
        Mockery::mock(DirectAdminClient::class),
        Mockery::mock(VirtualminClient::class),
        Mockery::mock(LiberuControlPanelClient::class),
        Mockery::mock(PricingService::class),
    );

    expect(fn () => $hostingService->provisionAccount($account, $product))
        ->toThrow(Exception::class, 'No available servers found');
});

it('routes virtualmin-gpl and virtualmin-pro servers to the Virtualmin client', function (string $controlPanel): void {
    $server = HostingServer::factory()->create([
        'control_panel' => $controlPanel,
    ]);
    $account = HostingAccount::factory()->create([
        'hosting_server_id' => $server->id,
        'control_panel' => $controlPanel,
        'status' => 'active',
    ]);

    $virtualminClient = Mockery::mock(VirtualminClient::class);
    $virtualminClient
        ->shouldReceive('setServer')
        ->once()
        ->with(Mockery::on(fn (HostingServer $s): bool => $s->id === $server->id));
    $virtualminClient
        ->shouldReceive('suspendAccount')
        ->once()
        ->with($account->username)
        ->andReturn(true);

    $hostingService = new HostingService(
        Mockery::mock(CpanelClient::class),
        Mockery::mock(PleskClient::class),
        Mockery::mock(DirectAdminClient::class),
        $virtualminClient,
        Mockery::mock(LiberuControlPanelClient::class),
        Mockery::mock(PricingService::class),
    );

    expect($hostingService->suspendAccount($account))->toBeTrue()
        ->and($account->fresh()->status)->toBe('suspended');
})->with(['virtualmin-gpl', 'virtualmin-pro']);
