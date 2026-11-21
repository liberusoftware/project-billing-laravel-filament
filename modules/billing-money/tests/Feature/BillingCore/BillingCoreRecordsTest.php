<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Core\Actions\CreateBillingAccount;
use Liberu\Billing\Core\Models\BillingContact;
use Liberu\Billing\Core\Models\BillingCurrency;
use Liberu\Billing\Core\Queries\ListBillingRecords;

beforeEach(function (): void {
    $this->billingUser = User::factory()->withPersonalTeam()->create();
    $this->billingUser->switchTeam($this->billingUser->ownedTeams()->firstOrFail());
    Sanctum::actingAs($this->billingUser, ['billing.billing-core.read', 'billing.billing-core.write']);
});

it('runs the module billing account lifecycle and scopes it to the current team', function (): void {
    $other = app(CreateBillingAccount::class)->execute(['team_id' => $this->billingUser->current_team_id + 1, 'name' => 'Other account', 'currency' => 'GBP']);

    $id = $this->postJson(route('billing.core.accounts.store'), ['name' => 'Operations', 'currency' => 'eur'])
        ->assertCreated()
        ->assertJsonPath('data.attributes.currency', 'EUR')
        ->json('data.id');

    $this->getJson(route('billing.core.accounts.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id);

    $this->patchJson(route('billing.core.accounts.update', $other), ['name' => 'Denied'])->assertNotFound();
    $this->patchJson(route('billing.core.accounts.update', $id), ['name' => 'Finance'])
        ->assertOk()->assertJsonPath('data.attributes.name', 'Finance');
    $this->patchJson(route('billing.core.accounts.status', $id), ['status' => 'suspended'])
        ->assertOk()->assertJsonPath('data.attributes.status', 'suspended');
    $this->deleteJson(route('billing.core.accounts.destroy', $id))->assertNoContent();
    $this->assertDatabaseMissing('billing_accounts', ['id' => $id]);
});

it('creates lists updates and deletes modular billing records', function (string $type, array $attributes, array $changes, string $field, mixed $value): void {
    $id = $this->postJson(route('billing.core.records.store', $type), $attributes)
        ->assertCreated()->json('data.id');

    $this->getJson(route('billing.core.records.index', $type))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

    $this->patchJson(route('billing.core.records.update', [$type, $id]), $changes)
        ->assertOk()->assertJsonPath("data.attributes.{$field}", $value);
    $this->deleteJson(route('billing.core.records.destroy', [$type, $id]))->assertNoContent();
    $this->getJson(route('billing.core.records.index', $type))->assertOk()->assertJsonCount(0, 'data');
})->with([
    'contacts' => ['contacts', ['name' => 'Accounts'], ['name' => 'Finance'], 'name', 'Finance'],
    'currencies' => ['currencies', ['code' => 'EUR', 'name' => 'Euro'], ['decimal_places' => 3], 'decimal_places', 3],
    'tax profiles' => ['tax-profiles', ['name' => 'VAT', 'rate' => 20], ['inclusive' => true], 'inclusive', true],
    'tax exemptions' => ['tax-exemptions', ['customer_id' => 1], ['reason' => 'Charity'], 'reason', 'Charity'],
    'sequences' => ['sequences', ['name' => 'Invoice'], ['next_number' => 42], 'next_number', 42],
    'terms' => ['terms', ['name' => 'Net 30'], ['due_days' => 45], 'due_days', 45],
    'settings' => ['settings', ['values' => ['week_start' => 'Sunday']], ['values' => ['week_start' => 'Monday']], 'values.week_start', 'Monday'],
]);

it('enforces billing record policy checks outside the API', function (): void {
    $own = new BillingContact(['team_id' => $this->billingUser->current_team_id, 'name' => 'Own contact']);
    $other = new BillingContact(['team_id' => $this->billingUser->current_team_id + 1, 'name' => 'Other contact']);
    $gate = Gate::forUser($this->billingUser);

    expect($gate->allows('view', $own))->toBeTrue()
        ->and($gate->denies('view', $other))->toBeTrue()
        ->and($gate->denies('update', $other))->toBeTrue();
});

it('limits users without an active team to shared billing accounts and currencies', function (): void {
    $shared = app(CreateBillingAccount::class)->execute(['name' => 'Shared', 'currency' => 'GBP']);
    app(CreateBillingAccount::class)->execute(['team_id' => $this->billingUser->current_team_id, 'name' => 'Private', 'currency' => 'GBP']);
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['billing.billing-core.read', 'billing.billing-core.write']);

    $this->getJson(route('billing.core.accounts.index'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $shared->getKey());
    $this->patchJson(route('billing.core.accounts.update', $shared), ['name' => 'Shared ledger'])->assertOk()
        ->assertJsonPath('data.attributes.name', 'Shared ledger');

    BillingCurrency::query()->create(['code' => 'GBP', 'name' => 'Pound']);
    BillingCurrency::query()->create(['team_id' => $this->billingUser->current_team_id, 'code' => 'USD', 'name' => 'US Dollar']);
    expect(app(ListBillingRecords::class)->execute(BillingCurrency::class, null)->pluck('code')->all())
        ->toBe(['GBP']);
});
