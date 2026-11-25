<?php

declare(strict_types=1);

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Gate::before(fn (): true => true);
    Sanctum::actingAs($this->user, ['*']);
});

it('sets status_changed_at when lifecycle_status changes on update', function (): void {
    $customer = Customer::factory()->create([
        'team_id' => $this->team->id,
        'lifecycle_status' => CustomerStatus::Prospect,
        'status_changed_at' => null,
    ]);

    $this->putJson("/api/customers/{$customer->id}", [
        'lifecycle_status' => CustomerStatus::Active->value,
    ])
        ->assertOk()
        ->assertJsonPath('lifecycle_status', CustomerStatus::Active->value);

    expect($customer->fresh()->status_changed_at)->not->toBeNull();
});

it('returns paginated invoices for a same-team customer and 404 for another team', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'team_id' => $this->team->id,
    ]);

    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCustomer = Customer::factory()->create(['team_id' => $otherTeam->id]);

    $this->getJson("/api/customers/{$customer->id}/invoices")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $invoice->id);

    $this->getJson("/api/customers/{$foreignCustomer->id}/invoices")
        ->assertNotFound();
});

it('returns paginated subscriptions for a same-team customer and 404 for another team', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $subscription = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'team_id' => $this->team->id,
    ]);

    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCustomer = Customer::factory()->create(['team_id' => $otherTeam->id]);

    $this->getJson("/api/customers/{$customer->id}/subscriptions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $subscription->id);

    $this->getJson("/api/customers/{$foreignCustomer->id}/subscriptions")
        ->assertNotFound();
});
