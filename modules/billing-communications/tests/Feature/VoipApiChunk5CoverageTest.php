<?php

declare(strict_types=1);

use App\Enums\VoipAccountStatus;
use App\Enums\VoipPlatform;
use App\Models\CallRateRule;
use App\Models\Customer;
use App\Models\DidNumber;
use App\Models\Team;
use App\Models\User;
use App\Models\VoipAccount;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_team_id' => $this->team->id]);

    Sanctum::actingAs($this->user, ['voip:read', 'voip:write']);
});

test('call rate controller lists, updates, deletes, and scopes rates by team', function (): void {
    $rate = CallRateRule::query()->create([
        'team_id' => $this->team->id,
        'name' => 'US Standard',
        'destination_prefix' => '+1',
        'rate_per_minute' => 0.05,
        'billing_increment_seconds' => 60,
        'currency' => 'USD',
    ]);

    $otherTeam = Team::factory()->create();
    $otherRate = CallRateRule::query()->create([
        'team_id' => $otherTeam->id,
        'name' => 'Other Team Rate',
        'destination_prefix' => '+44',
        'rate_per_minute' => 0.10,
        'billing_increment_seconds' => 60,
        'currency' => 'GBP',
    ]);

    $this->getJson('/api/voip/rates')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $rate->id);

    $this->patchJson("/api/voip/rates/{$rate->id}", [
        'name' => 'US Premium',
        'rate_per_minute' => 0.08,
        'is_active' => false,
    ])
        ->assertOk()
        ->assertJsonPath('name', 'US Premium')
        ->assertJsonPath('is_active', false);

    $this->patchJson("/api/voip/rates/{$otherRate->id}", ['name' => 'Blocked'])
        ->assertNotFound();

    $this->deleteJson("/api/voip/rates/{$rate->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('call_rate_rules', ['id' => $rate->id]);
});

test('did number controller lists and deletes team DIDs', function (): void {
    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $account = VoipAccount::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'sip_username' => 'did-owner-1',
    ]);

    $did = DidNumber::query()->create([
        'team_id' => $this->team->id,
        'voip_account_id' => $account->id,
        'number' => '+15551112222',
        'country_code' => 'US',
        'monthly_price' => 3.00,
        'currency' => 'USD',
        'status' => 'assigned',
    ]);

    $otherTeam = Team::factory()->create();
    $otherDid = DidNumber::query()->create([
        'team_id' => $otherTeam->id,
        'number' => '+442079460000',
        'country_code' => 'GB',
        'monthly_price' => 4.00,
        'currency' => 'GBP',
        'status' => 'available',
    ]);

    $this->getJson('/api/voip/dids')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $did->id)
        ->assertJsonPath('data.0.voip_account.sip_username', 'did-owner-1');

    $this->deleteJson("/api/voip/dids/{$otherDid->id}")
        ->assertNotFound();

    $this->deleteJson("/api/voip/dids/{$did->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('did_numbers', ['id' => $did->id]);
});

test('voip account controller lists, updates, provisions, and deletes accounts', function (): void {
    config([
        'voip.platforms.asterisk.url' => 'https://asterisk.example.test/api',
        'voip.platforms.asterisk.token' => 'test-voip-token',
    ]);
    Http::fake(['*' => Http::response([], 201)]);

    $customer = Customer::factory()->create(['team_id' => $this->team->id]);
    $account = VoipAccount::factory()->create([
        'team_id' => $this->team->id,
        'customer_id' => $customer->id,
        'platform' => VoipPlatform::Asterisk,
        'status' => VoipAccountStatus::Pending,
        'sip_username' => 'sip-ctrl-100',
    ]);

    $this->getJson('/api/voip/accounts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $account->id)
        ->assertJsonPath('data.0.customer.id', $customer->id);

    $this->patchJson("/api/voip/accounts/{$account->id}", [
        'caller_id' => '+15553334444',
        'max_concurrent_calls' => 5,
        'international_enabled' => true,
    ])
        ->assertOk()
        ->assertJsonPath('caller_id', '+15553334444')
        ->assertJsonPath('max_concurrent_calls', 5)
        ->assertJsonPath('international_enabled', true);

    $this->postJson("/api/voip/accounts/{$account->id}/provision")
        ->assertOk()
        ->assertJsonPath('status', VoipAccountStatus::Active->value);

    $this->deleteJson("/api/voip/accounts/{$account->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('voip_accounts', ['id' => $account->id]);
});
