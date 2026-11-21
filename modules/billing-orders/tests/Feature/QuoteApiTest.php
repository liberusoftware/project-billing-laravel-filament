<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->foreignTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $this->customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $this->foreignCustomer = Customer::factory()->create(['team_id' => $this->foreignTeam->id]);
    $this->quote = app(QuoteService::class)->createQuote([
        'customer_id' => $this->customer->id, 'team_id' => $this->team->id, 'title' => 'Hosting proposal',
        'items' => [['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 25]],
    ]);
    $this->foreignQuote = Quote::create([
        'customer_id' => $this->foreignCustomer->id, 'team_id' => $this->foreignTeam->id,
        'title' => 'Private proposal', 'status' => 'draft', 'total' => 5000,
    ]);
    // Exercise the controller's tenant boundary independently of Shield permissions.
    Gate::before(fn (): true => true);
    Sanctum::actingAs($this->user, ['quotes:read', 'quotes:write']);
});

it('lists filters and shows only quotes in the current team', function () {
    $this->getJson('/api/quotes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->quote->id);
    $this->getJson('/api/quotes?status=draft&customer_id='.$this->customer->id.'&per_page=1')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('per_page', 1)->assertJsonPath('data.0.customer.id', $this->customer->id);
    $this->getJson('/api/quotes?status=accepted')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/quotes/'.$this->quote->id)->assertOk()
        ->assertJsonPath('data.customer.id', $this->customer->id)->assertJsonPath('data.items.0.description', 'Hosting');
    expect($this->quote->team->is($this->team))->toBeTrue()
        ->and($this->quote->items->first()->quote->is($this->quote))->toBeTrue();
});

it('creates a calculated quote and stamps the authenticated team', function () {
    $response = $this->postJson('/api/quotes', [
        'customer_id' => $this->customer->id, 'team_id' => $this->foreignTeam->id,
        'title' => 'New proposal', 'currency' => 'EUR', 'valid_until' => now()->addDay()->toDateString(),
        'notes' => 'Reviewed', 'terms' => 'Thirty days',
        'items' => [
            ['description' => 'Hosting', 'quantity' => 2, 'unit_price' => 25, 'sort_order' => 1],
            ['description' => 'Domain', 'quantity' => 1, 'unit_price' => 15, 'sort_order' => 0],
        ],
    ])->assertCreated()->assertJsonPath('data.team_id', $this->team->id)
        ->assertJsonPath('data.total', '65.00')->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.items.0.description', 'Domain');
    $this->assertDatabaseHas('quotes', ['id' => $response->json('data.id'), 'team_id' => $this->team->id]);
});

it('rejects foreign customers and invalid quote items', function () {
    $this->postJson('/api/quotes', [
        'customer_id' => $this->foreignCustomer->id, 'title' => '',
        'items' => [['description' => '', 'quantity' => 0, 'unit_price' => -1]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'title', 'items.0.description', 'items.0.quantity', 'items.0.unit_price']);
    expect(Quote::count())->toBe(2);
});

it('updates quote items and preserves them when only metadata changes', function () {
    $url = '/api/quotes/'.$this->quote->id;
    $this->patchJson($url, ['title' => 'Revised proposal', 'items' => [['description' => 'Upgrade', 'quantity' => 3, 'unit_price' => 30]]])
        ->assertOk()->assertJsonPath('data.total', '90.00')->assertJsonPath('data.items.0.description', 'Upgrade');
    $this->putJson($url, ['notes' => 'Approved internally'])->assertOk()
        ->assertJsonPath('data.total', '90.00')->assertJsonPath('data.notes', 'Approved internally')->assertJsonCount(1, 'data.items');
});

it('refuses changes to finalized quotes', function (string $status) {
    $this->quote->update(['status' => $status]);
    $this->patchJson('/api/quotes/'.$this->quote->id, ['title' => 'Tampered'])
        ->assertUnprocessable()->assertJsonPath('message', 'Cannot update an accepted or declined quote.');
    expect($this->quote->fresh()->title)->toBe('Hosting proposal');
})->with(['accepted', 'declined']);

it('deletes only draft quotes', function () {
    $this->deleteJson('/api/quotes/'.$this->quote->id)->assertNoContent();
    $this->assertDatabaseMissing('quotes', ['id' => $this->quote->id]);
    $this->foreignQuote->update(['team_id' => $this->team->id, 'status' => 'sent']);
    $this->deleteJson('/api/quotes/'.$this->foreignQuote->id)->assertUnprocessable()
        ->assertJsonPath('message', 'Only draft quotes can be deleted.');
    $this->assertDatabaseHas('quotes', ['id' => $this->foreignQuote->id]);
});

it('sends and resends a draft quote', function () {
    $url = '/api/quotes/'.$this->quote->id.'/send';
    $this->postJson($url)->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('message', 'Quote sent successfully.');
    $this->postJson($url)->assertOk()->assertJsonPath('data.status', 'sent');
    expect($this->quote->fresh()->sent_at)->not->toBeNull();
});

it('accepts or declines quotes that have been sent or viewed', function (string $status, string $action, string $result) {
    $this->quote->update(['status' => $status]);
    $this->postJson('/api/quotes/'.$this->quote->id.'/'.$action)->assertOk()->assertJsonPath('data.status', $result);
    expect($this->quote->fresh()->getAttribute($result.'_at'))->not->toBeNull();
})->with([
    ['sent', 'accept', 'accepted'], ['viewed', 'accept', 'accepted'],
    ['sent', 'decline', 'declined'], ['viewed', 'decline', 'declined'],
]);

it('refuses lifecycle actions in incompatible states', function () {
    $url = '/api/quotes/'.$this->quote->id;
    foreach (['accept', 'decline', 'convert'] as $action) {
        $this->postJson($url.'/'.$action)->assertUnprocessable();
    }
    $this->quote->update(['status' => 'accepted']);
    $this->postJson($url.'/send')->assertUnprocessable();
    expect(Invoice::count())->toBe(0);
});

it('converts an accepted quote into an invoice for the same customer', function () {
    $this->quote->update(['status' => 'accepted']);
    $response = $this->postJson('/api/quotes/'.$this->quote->id.'/convert')->assertCreated()
        ->assertJsonPath('data.customer_id', $this->customer->id)
        ->assertJsonPath('data.total_amount', 50)
        ->assertJsonPath('message', 'Quote successfully converted to invoice.');
    $this->assertDatabaseHas('invoices', ['id' => $response->json('data.id'), 'customer_id' => $this->customer->id]);
});

it('treats another teams quote as missing for reads writes and lifecycle actions', function () {
    $url = '/api/quotes/'.$this->foreignQuote->id;
    $this->getJson($url)->assertNotFound();
    $this->patchJson($url, ['title' => 'Tampered'])->assertNotFound();
    $this->deleteJson($url)->assertNotFound();
    foreach (['send', 'accept', 'decline', 'convert'] as $action) {
        $this->postJson($url.'/'.$action)->assertNotFound();
    }
    expect($this->foreignQuote->fresh()->status)->toBe('draft')
        ->and($this->foreignQuote->fresh()->title)->toBe('Private proposal');
});

it('derives quote statistics from the authenticated team', function () {
    $this->getJson('/api/quotes-statistics?team_id='.$this->foreignTeam->id)->assertOk()
        ->assertJsonPath('data.draft.count', 1)->assertJsonPath('data.draft.value', 50);
});

it('does not aggregate other teams quote statistics for users without a team', function () {
    Sanctum::actingAs(User::factory()->create(), ['quotes:read']);
    $this->getJson('/api/quotes-statistics')->assertOk()
        ->assertJsonPath('data.draft.count', 0)->assertJsonPath('data.draft.value', 0);
});

it('requires quote token abilities for reads and writes', function () {
    Sanctum::actingAs($this->user, ['invoices:read']);
    $this->getJson('/api/quotes')->assertForbidden();
    $this->postJson('/api/quotes', [])->assertForbidden();
    Sanctum::actingAs($this->user, ['quotes:read']);
    $this->getJson('/api/quotes')->assertOk();
    $this->patchJson('/api/quotes/'.$this->quote->id, ['title' => 'Tampered'])->assertForbidden();
});
