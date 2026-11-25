<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\HostingServer;
use App\Models\Products_Service;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutVite();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
});

it('lists client services for users with and without a customer profile', function (): void {
    $this->actingAs($this->user)
        ->get(route('client.services.index'))
        ->assertOk()
        ->assertViewIs('client.services.index')
        ->assertViewHas('subscriptions', fn ($subscriptions): bool => $subscriptions->isEmpty());

    $customer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);
    $service = Products_Service::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Starter Hosting',
        'type' => 'hosting',
        'base_price' => 25.00,
    ]);
    $subscription = Subscription::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'product_service_id' => $service->id,
        'status' => 'active',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(25),
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('client.services.index'))
        ->assertOk()
        ->assertViewIs('client.services.index')
        ->assertViewHas('subscriptions', fn ($subscriptions): bool => $subscriptions->contains('id', $subscription->id));
});

it('shows a client service with available upgrades and downgrades', function (): void {
    $customer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);
    $server = HostingServer::factory()->create(['team_id' => $this->team->id]);

    $lowerPlan = Products_Service::factory()->create([
        'team_id' => $this->team->id,
        'hosting_server_id' => $server->id,
        'name' => 'Basic Hosting',
        'type' => 'hosting',
        'base_price' => 15.00,
    ]);
    $currentPlan = Products_Service::factory()->create([
        'team_id' => $this->team->id,
        'hosting_server_id' => $server->id,
        'name' => 'Pro Hosting',
        'type' => 'hosting',
        'base_price' => 30.00,
    ]);
    $higherPlan = Products_Service::factory()->create([
        'team_id' => $this->team->id,
        'hosting_server_id' => $server->id,
        'name' => 'Enterprise Hosting',
        'type' => 'hosting',
        'base_price' => 60.00,
    ]);

    $subscription = Subscription::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'product_service_id' => $currentPlan->id,
        'status' => 'active',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(25),
        'scheduled_change' => null,
    ]);

    $this->actingAs($this->user)
        ->get(route('client.services.show', $subscription))
        ->assertOk()
        ->assertViewIs('client.services.show')
        ->assertViewHas('availableUpgrades', fn ($upgrades): bool => $upgrades->pluck('id')->all() === [$higherPlan->id])
        ->assertViewHas('availableDowngrades', fn ($downgrades): bool => $downgrades->pluck('id')->all() === [$lowerPlan->id]);
});

it('returns 404 when showing a service owned by another user or without a product service', function (): void {
    $customer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);
    $otherUser = User::factory()->withPersonalTeam()->create();

    $subscription = Subscription::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'status' => 'active',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(25),
    ]);

    $this->actingAs($otherUser)
        ->get(route('client.services.show', $subscription))
        ->assertNotFound();

    $planOnlySubscription = Subscription::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'product_service_id' => null,
        'status' => 'active',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(25),
    ]);

    $this->actingAs($this->user)
        ->get(route('client.services.show', $planOnlySubscription))
        ->assertNotFound();
});

it('schedules a downgrade at end of billing period and rejects invalid downgrades', function (): void {
    $customer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);
    $server = HostingServer::factory()->create(['team_id' => $this->team->id]);

    $currentPlan = Products_Service::factory()->create([
        'team_id' => $this->team->id,
        'hosting_server_id' => $server->id,
        'type' => 'hosting',
        'base_price' => 50.00,
    ]);
    $lowerPlan = Products_Service::factory()->create([
        'team_id' => $this->team->id,
        'hosting_server_id' => $server->id,
        'type' => 'hosting',
        'base_price' => 20.00,
    ]);
    $higherPlan = Products_Service::factory()->create([
        'team_id' => $this->team->id,
        'hosting_server_id' => $server->id,
        'type' => 'hosting',
        'base_price' => 80.00,
    ]);

    $subscription = Subscription::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'product_service_id' => $currentPlan->id,
        'status' => 'active',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(25),
        'scheduled_change' => null,
    ]);

    $this->actingAs($this->user)
        ->post(route('client.services.downgrade', $subscription), ['new_service_id' => $higherPlan->id])
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->post(route('client.services.downgrade', $subscription), ['new_service_id' => $lowerPlan->id])
        ->assertRedirect(route('client.services.show', $subscription))
        ->assertSessionHas('success', 'Service scheduled for downgrade at end of billing period');

    $scheduled = $subscription->fresh()->scheduled_change;
    expect($scheduled)->toBeArray()
        ->and($scheduled['type'])->toBe('downgrade')
        ->and($scheduled['product_service_id'])->toBe($lowerPlan->id);
});

it('schedules cancellation at end of billing period and enforces guards', function (): void {
    $customer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]);

    $subscription = Subscription::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'status' => 'active',
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(25),
        'scheduled_change' => null,
    ]);

    $this->actingAs($this->user)
        ->post(route('client.services.cancel', $subscription))
        ->assertRedirect(route('client.services.index'))
        ->assertSessionHas('success', 'Service scheduled for cancellation at end of billing period');

    expect($subscription->fresh()->scheduled_change['type'])->toBe('cancel');

    // Duplicate cancel while scheduled_change exists -> 422
    $this->actingAs($this->user)
        ->post(route('client.services.cancel', $subscription->fresh()))
        ->assertUnprocessable();

    // Expired subscription -> 422
    $expired = Subscription::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'status' => 'active',
        'start_date' => now()->subDays(30),
        'end_date' => now()->subDay(),
        'scheduled_change' => null,
    ]);

    $this->actingAs($this->user)
        ->post(route('client.services.cancel', $expired))
        ->assertUnprocessable();
});
