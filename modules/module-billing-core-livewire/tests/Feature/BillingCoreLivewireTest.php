<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Core\Enums\BillingAccountStatus;
use Liberu\Billing\Core\Livewire\Components\BillingAccounts;
use Liberu\Billing\Core\Livewire\Components\BillingCoreRecords;
use Liberu\Billing\Core\Livewire\Components\CurrencyConverter;
use Liberu\Billing\Core\Livewire\Components\TaxCalculator;
use Liberu\Billing\Core\Models\BillingAccount;
use Liberu\Billing\Core\Models\BillingContact;
use Liberu\Billing\Core\Models\BillingCurrency;
use Liberu\Billing\Core\Models\BillingSequence;
use Liberu\Billing\Core\Models\BillingSetting;
use Liberu\Billing\Core\Models\BillingTaxExemption;
use Liberu\Billing\Core\Models\BillingTaxProfile;
use Liberu\Billing\Core\Models\BillingTerm;
use Livewire\Livewire;

it('creates, updates, and transitions billing accounts via Livewire', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.billing-core.read', 'billing.billing-core.write']);

    $component = Livewire::test(BillingAccounts::class)
        ->set('showCreate', true)
        ->set('name', 'Livewire Account')
        ->set('currency', 'USD')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false);

    $account = BillingAccount::query()->where('name', 'Livewire Account')->sole();

    $component
        ->set('selectedAccountId', $account->id)
        ->set('name', 'Livewire Account Renamed')
        ->set('currency', 'EUR')
        ->call('updateAccount')
        ->assertHasNoErrors();

    expect($account->fresh()->name)->toBe('Livewire Account Renamed')
        ->and($account->fresh()->currency)->toBe('EUR');

    $component
        ->set('selectedAccountId', $account->id)
        ->set('status', 'suspended')
        ->call('transitionAccount')
        ->assertHasNoErrors();

    expect($account->fresh()->status)->toBe(BillingAccountStatus::Suspended);
});

it('creates and updates all billing core record types via Livewire', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.billing-core.read', 'billing.billing-core.write']);

    // contacts
    $component = Livewire::test(BillingCoreRecords::class)
        ->set('type', 'contacts')
        ->set('showCreate', true)
        ->set('name', 'Contact One')
        ->set('email', 'one@example.com')
        ->call('save')
        ->assertHasNoErrors();

    $contact = BillingContact::query()->where('name', 'Contact One')->sole();
    $component
        ->set('selectedRecordId', $contact->id)
        ->set('name', 'Contact Two')
        ->set('email', 'two@example.com')
        ->call('update')
        ->assertHasNoErrors();
    expect($contact->fresh()->name)->toBe('Contact Two');

    // currencies
    $component->set('type', 'currencies')
        ->set('code', 'GBP')
        ->call('save')
        ->assertHasNoErrors();
    expect(BillingCurrency::query()->where('code', 'GBP')->exists())->toBeTrue();

    // tax-profiles
    $component->set('type', 'tax-profiles')
        ->set('name', 'Standard VAT')
        ->set('rate', '20')
        ->call('save')
        ->assertHasNoErrors();
    expect(BillingTaxProfile::query()->where('name', 'Standard VAT')->exists())->toBeTrue();

    // tax-exemptions
    $component->set('type', 'tax-exemptions')
        ->set('customerId', 101)
        ->set('expiresAt', now()->addYear()->toDateString())
        ->set('reason', 'Non-profit')
        ->call('save')
        ->assertHasNoErrors();
    expect(BillingTaxExemption::query()->where('customer_id', 101)->exists())->toBeTrue();

    // sequences
    $component->set('type', 'sequences')
        ->set('name', 'Receipts')
        ->set('prefix', 'RCP-')
        ->set('nextNumber', 10)
        ->call('save')
        ->assertHasNoErrors();
    expect(BillingSequence::query()->where('name', 'Receipts')->exists())->toBeTrue();

    // terms
    $component->set('type', 'terms')
        ->set('name', 'Net 30')
        ->set('dueDays', 30)
        ->call('save')
        ->assertHasNoErrors();
    expect(BillingTerm::query()->where('name', 'Net 30')->exists())->toBeTrue();

    // settings
    $component->set('type', 'settings')
        ->set('valuesJson', '{"footer":"Thanks"}')
        ->call('save')
        ->assertHasNoErrors();
    expect(BillingSetting::query()->where('team_id', $team->id)->exists())->toBeTrue();
});

it('rejects unknown billing core record types and covers default fallbacks', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.billing-core.read', 'billing.billing-core.write']);

    $instance = new BillingCoreRecords();
    $instance->type = 'unknown';

    $rules = (new ReflectionMethod($instance, 'rules'))->invoke($instance);
    $attributes = (new ReflectionMethod($instance, 'attributes'))->invoke($instance);

    expect($rules)->toBe([])
        ->and($attributes)->toBe([]);

    expect(fn () => (new ReflectionMethod($instance, 'modelClass'))->invoke($instance))
        ->toThrow(InvalidArgumentException::class);
});

it('converts currencies and calculates taxes via Livewire components', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    $this->actingAs($user, 'web');
    Sanctum::actingAs($user, ['billing.billing-core.read', 'billing.billing-core.write']);

    BillingCurrency::query()->create([
        'team_id' => $team->id,
        'code' => 'USD',
        'name' => 'US Dollar',
        'decimal_places' => 2,
        'exchange_rate' => 1.0,
        'enabled' => true,
    ]);
    BillingCurrency::query()->create([
        'team_id' => $team->id,
        'code' => 'EUR',
        'name' => 'Euro',
        'decimal_places' => 2,
        'exchange_rate' => 0.9,
        'enabled' => true,
    ]);

    Livewire::test(CurrencyConverter::class)
        ->set('amount', '100')
        ->set('from', 'USD')
        ->set('to', 'EUR')
        ->call('convert')
        ->assertHasNoErrors()
        ->assertSet('result.amount', 90.0)
        ->assertSet('result.to', 'EUR');

    BillingTaxProfile::query()->create([
        'team_id' => $team->id,
        'name' => 'UK VAT',
        'rate' => 20,
        'jurisdiction' => 'GB',
        'inclusive' => false,
        'enabled' => true,
    ]);

    Livewire::test(TaxCalculator::class)
        ->set('amount', '100')
        ->set('jurisdiction', 'GB')
        ->set('customerId', '7')
        ->call('calculate')
        ->assertHasNoErrors()
        ->assertSet('result.tax', 20.0)
        ->assertSet('result.total', 120.0);
});
