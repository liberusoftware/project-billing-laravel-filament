<?php

use App\Models\CannedResponse;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $foreignTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $this->globalResponse = CannedResponse::create([
        'title' => 'A Welcome', 'shortcode' => 'global-welcome', 'content' => 'Welcome {{client_name}}!',
        'category' => 'general', 'is_active' => true, 'usage_count' => 2,
    ]);
    $this->teamResponse = CannedResponse::create([
        'team_id' => $this->team->id, 'title' => 'B Welcome', 'shortcode' => 'team-welcome',
        'content' => 'Welcome to ticket {{ticket_id}}, {{client_name}}.', 'category' => 'support',
        'is_active' => true, 'usage_count' => 3,
    ]);
    $this->foreignResponse = CannedResponse::create([
        'team_id' => $foreignTeam->id, 'title' => 'C Welcome', 'shortcode' => 'private-welcome',
        'content' => 'Welcome from a different team.', 'category' => 'private', 'is_active' => true, 'usage_count' => 100,
    ]);
    $this->inactiveResponse = CannedResponse::create([
        'team_id' => $this->team->id, 'title' => 'D Welcome', 'shortcode' => 'inactive-welcome',
        'content' => 'Disabled template.', 'category' => 'support', 'is_active' => false, 'usage_count' => 200,
    ]);
    Sanctum::actingAs($this->user, ['canned-responses:read', 'canned-responses:write']);
});

it('lists and filters the current team and global templates without exposing other teams', function () {
    $this->getJson('/api/canned-responses')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $this->globalResponse->id)->assertJsonPath('data.1.id', $this->teamResponse->id);
    $this->getJson('/api/canned-responses?category=support')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->teamResponse->id);
    $this->getJson('/api/canned-responses?category=private')->assertOk()->assertJsonCount(0, 'data');
});

it('looks up team and global shortcodes while hiding inactive foreign and missing templates', function () {
    foreach ([$this->teamResponse, $this->globalResponse] as $response) {
        $this->getJson('/api/canned-responses/'.$response->shortcode)->assertOk()->assertJsonPath('data.id', $response->id);
    }
    foreach ([$this->foreignResponse->shortcode, $this->inactiveResponse->shortcode, 'missing'] as $shortcode) {
        $this->getJson('/api/canned-responses/'.$shortcode)->assertNotFound()->assertJsonPath('message', 'Canned response not found');
        $this->postJson('/api/canned-responses/'.$shortcode.'/use')->assertNotFound()->assertJsonPath('message', 'Canned response not found');
    }
    expect($this->foreignResponse->fresh()->usage_count)->toBe(100)
        ->and($this->inactiveResponse->fresh()->usage_count)->toBe(200);
});

it('substitutes caller variables and records usage only for the selected template', function () {
    $this->postJson('/api/canned-responses/team-welcome/use', ['variables' => ['client_name' => 'Alex', 'ticket_id' => '42']])
        ->assertOk()->assertJsonPath('content', 'Welcome to ticket 42, Alex.');
    $this->postJson('/api/canned-responses/global-welcome/use')->assertOk()->assertJsonPath('content', 'Welcome {{client_name}}!');
    $this->postJson('/api/canned-responses/global-welcome/use', ['variables' => null])->assertOk()->assertJsonPath('content', 'Welcome {{client_name}}!');
    expect($this->teamResponse->fresh()->usage_count)->toBe(4)
        ->and($this->teamResponse->fresh()->last_used_at)->not->toBeNull()
        ->and($this->globalResponse->fresh()->usage_count)->toBe(4);
});

it('rejects invalid variable payloads before mutating template usage', function () {
    $this->postJson('/api/canned-responses/team-welcome/use', ['variables' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('variables');
    expect($this->teamResponse->fresh()->usage_count)->toBe(3);
});

it('searches and ranks only visible active templates', function () {
    $this->getJson('/api/canned-responses/search?q=Welcome')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $this->teamResponse->id)->assertJsonPath('data.1.id', $this->globalResponse->id);
    $this->getJson('/api/canned-responses/search?q=x')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/canned-responses/search')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/api/canned-responses/most-used')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $this->teamResponse->id);
    $this->getJson('/api/canned-responses/most-used?limit=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->teamResponse->id);
});

it('returns visible categories and documented substitution variables', function () {
    $response = $this->getJson('/api/canned-responses/categories')->assertOk();
    expect($response->json('data'))->toEqualCanonicalizing(['general', 'support']);
    $this->getJson('/api/canned-responses/variables')->assertOk()
        ->assertJsonPath('data.client_name', 'Client Name')->assertJsonPath('data.ticket_id', 'Ticket ID');
});

it('limits users without a current team to global templates', function () {
    Sanctum::actingAs(User::factory()->create(), ['canned-responses:read', 'canned-responses:write']);
    foreach (['', '/search?q=Welcome', '/most-used'] as $suffix) {
        $this->getJson('/api/canned-responses'.$suffix)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->globalResponse->id);
    }
    $response = $this->getJson('/api/canned-responses/categories')->assertOk();
    expect($response->json('data'))->toBe(['general']);
    foreach ([$this->teamResponse, $this->foreignResponse] as $response) {
        $this->getJson('/api/canned-responses/'.$response->shortcode)->assertNotFound();
        $this->postJson('/api/canned-responses/'.$response->shortcode.'/use')->assertNotFound();
    }
    $this->getJson('/api/canned-responses/global-welcome')->assertOk();
    $this->postJson('/api/canned-responses/global-welcome/use')->assertOk();
    expect($this->teamResponse->fresh()->usage_count)->toBe(3)->and($this->foreignResponse->fresh()->usage_count)->toBe(100);
});

it('enforces token abilities for reading and using templates', function () {
    Sanctum::actingAs($this->user, ['invoices:read']);
    $this->getJson('/api/canned-responses')->assertForbidden();
    $this->postJson('/api/canned-responses/team-welcome/use')->assertForbidden();
    Sanctum::actingAs($this->user, ['canned-responses:read']);
    $this->getJson('/api/canned-responses')->assertOk();
    $this->postJson('/api/canned-responses/team-welcome/use')->assertForbidden();
});

it('resolves template ownership and lists distinct non-null model categories', function () {
    CannedResponse::create(['title' => 'Uncategorized', 'shortcode' => 'uncategorized', 'content' => 'No category', 'category' => null]);
    expect($this->teamResponse->team->is($this->team))->toBeTrue()
        ->and($this->globalResponse->team)->toBeNull()
        ->and(CannedResponse::getCategories())->toEqualCanonicalizing(['general', 'support', 'private']);
});
