<?php

declare(strict_types=1);

use App\Enums\ContactType;
use App\Models\ClientContact;
use App\Models\Customer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Sanctum::actingAs($this->user, ['*']);
});

it('lists contacts for a same-team customer and blocks cross-team access', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCustomer = Customer::factory()->create(['team_id' => $otherTeam->id]);

    ClientContact::create([
        'customer_id' => $customer->id,
        'first_name' => 'Alice',
        'last_name' => 'Primary',
        'email' => 'alice@example.com',
        'contact_type' => ContactType::Primary,
        'is_primary' => true,
    ]);
    ClientContact::create([
        'customer_id' => $customer->id,
        'first_name' => 'Bob',
        'last_name' => 'Billing',
        'email' => 'bob@example.com',
        'contact_type' => ContactType::Billing,
        'is_primary' => false,
    ]);

    $this->getJson("/api/customers/{$customer->id}/contacts")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.email', 'alice@example.com');

    $this->getJson("/api/customers/{$foreignCustomer->id}/contacts")
        ->assertNotFound();
});

it('shows a single contact and returns 404 for mismatched customer', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $otherCustomer = Customer::factory()->create(['team_id' => $this->team->id]);

    $contact = ClientContact::create([
        'customer_id' => $customer->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'contact_type' => ContactType::Technical,
        'is_primary' => true,
    ]);

    $this->getJson("/api/customers/{$customer->id}/contacts/{$contact->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $contact->id)
        ->assertJsonPath('data.email', 'jane@example.com');

    $this->getJson("/api/customers/{$otherCustomer->id}/contacts/{$contact->id}")
        ->assertNotFound();
});

it('creates a contact with full payload and blocks cross-team creation', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCustomer = Customer::factory()->create(['team_id' => $otherTeam->id]);

    $payload = [
        'first_name' => 'Clara',
        'last_name' => 'Smith',
        'email' => 'clara@example.com',
        'phone' => '+441234567890',
        'title' => 'Finance Lead',
        'contact_type' => ContactType::Billing->value,
        'is_primary' => true,
        'can_view_invoices' => true,
        'can_make_payments' => true,
        'can_manage_services' => false,
    ];

    $response = $this->postJson("/api/customers/{$customer->id}/contacts", $payload);

    $response->assertCreated()
        ->assertJsonPath('data.first_name', 'Clara')
        ->assertJsonPath('data.contact_type', ContactType::Billing->value)
        ->assertJsonPath('data.is_primary', true);

    $this->assertDatabaseHas('client_contacts', [
        'customer_id' => $customer->id,
        'email' => 'clara@example.com',
        'title' => 'Finance Lead',
    ]);

    $this->postJson("/api/customers/{$foreignCustomer->id}/contacts", $payload)
        ->assertNotFound();
});

it('updates a contact and returns 404 when contact does not belong to customer', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $otherCustomer = Customer::factory()->create(['team_id' => $this->team->id]);

    $contact = ClientContact::create([
        'customer_id' => $customer->id,
        'first_name' => 'David',
        'last_name' => 'Jones',
        'email' => 'david@example.com',
        'contact_type' => ContactType::Administrative,
        'is_primary' => false,
    ]);

    $this->putJson("/api/customers/{$customer->id}/contacts/{$contact->id}", [
        'first_name' => 'Dave',
        'title' => 'Operations Director',
        'contact_type' => ContactType::Technical->value,
        'can_manage_services' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Dave')
        ->assertJsonPath('data.title', 'Operations Director')
        ->assertJsonPath('data.contact_type', ContactType::Technical->value);

    $this->assertDatabaseHas('client_contacts', [
        'id' => $contact->id,
        'first_name' => 'Dave',
        'title' => 'Operations Director',
    ]);

    $this->putJson("/api/customers/{$otherCustomer->id}/contacts/{$contact->id}", [
        'first_name' => 'Hacked',
    ])->assertNotFound();
});

it('promotes a contact to primary and returns 404 for mismatched customer', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $otherCustomer = Customer::factory()->create(['team_id' => $this->team->id]);

    $primary = ClientContact::create([
        'customer_id' => $customer->id,
        'first_name' => 'First',
        'last_name' => 'Contact',
        'email' => 'first@example.com',
        'is_primary' => true,
    ]);
    $secondary = ClientContact::create([
        'customer_id' => $customer->id,
        'first_name' => 'Second',
        'last_name' => 'Contact',
        'email' => 'second@example.com',
        'is_primary' => false,
    ]);

    $this->postJson("/api/customers/{$customer->id}/contacts/{$secondary->id}/make-primary")
        ->assertOk()
        ->assertJsonPath('message', 'Contact set as primary.')
        ->assertJsonPath('data.id', $secondary->id)
        ->assertJsonPath('data.is_primary', true);

    expect($primary->fresh()->is_primary)->toBeFalse()
        ->and($secondary->fresh()->is_primary)->toBeTrue();

    $this->postJson("/api/customers/{$otherCustomer->id}/contacts/{$secondary->id}/make-primary")
        ->assertNotFound();
});

it('deletes a non-primary contact, rejects deleting primary, and returns 404 for mismatch', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $otherCustomer = Customer::factory()->create(['team_id' => $this->team->id]);

    $primary = ClientContact::create([
        'customer_id' => $customer->id,
        'first_name' => 'Primary',
        'last_name' => 'Person',
        'email' => 'primary@example.com',
        'is_primary' => true,
    ]);
    $secondary = ClientContact::create([
        'customer_id' => $customer->id,
        'first_name' => 'Secondary',
        'last_name' => 'Person',
        'email' => 'secondary@example.com',
        'is_primary' => false,
    ]);

    $this->deleteJson("/api/customers/{$otherCustomer->id}/contacts/{$secondary->id}")
        ->assertNotFound();

    $this->deleteJson("/api/customers/{$customer->id}/contacts/{$primary->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Cannot delete the primary contact.');

    $this->deleteJson("/api/customers/{$customer->id}/contacts/{$secondary->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('client_contacts', ['id' => $secondary->id]);
    $this->assertDatabaseHas('client_contacts', ['id' => $primary->id]);
});
