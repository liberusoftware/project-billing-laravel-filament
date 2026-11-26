<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Core\Enums\BillingAccountStatus;
use Liberu\Billing\Core\Filament\BillingCoreFilamentPlugin;
use Liberu\Billing\Core\Filament\Resources\BillingAccountResource;
use Liberu\Billing\Core\Filament\Resources\BillingAccountResource\Pages\CreateBillingAccount;
use Liberu\Billing\Core\Filament\Resources\BillingAccountResource\Pages\EditBillingAccount;
use Liberu\Billing\Core\Filament\Resources\BillingAccountResource\Pages\ListBillingAccounts;
use Liberu\Billing\Core\Filament\Resources\BillingContactResource\Pages\CreateBillingContact;
use Liberu\Billing\Core\Filament\Resources\BillingContactResource\Pages\EditBillingContact;
use Liberu\Billing\Core\Filament\Resources\BillingContactResource\Pages\ListBillingContacts;
use Liberu\Billing\Core\Filament\Resources\BillingCoreResource;
use Liberu\Billing\Core\Filament\Resources\BillingCurrencyResource\Pages\CreateBillingCurrency;
use Liberu\Billing\Core\Filament\Resources\BillingCurrencyResource\Pages\EditBillingCurrency;
use Liberu\Billing\Core\Filament\Resources\BillingCurrencyResource\Pages\ListBillingCurrencies;
use Liberu\Billing\Core\Filament\Resources\BillingSequenceResource\Pages\CreateBillingSequence;
use Liberu\Billing\Core\Filament\Resources\BillingSequenceResource\Pages\EditBillingSequence;
use Liberu\Billing\Core\Filament\Resources\BillingSequenceResource\Pages\ListBillingSequences;
use Liberu\Billing\Core\Filament\Resources\BillingSettingResource\Pages\CreateBillingSetting;
use Liberu\Billing\Core\Filament\Resources\BillingSettingResource\Pages\EditBillingSetting;
use Liberu\Billing\Core\Filament\Resources\BillingSettingResource\Pages\ListBillingSettings;
use Liberu\Billing\Core\Filament\Resources\BillingTaxExemptionResource\Pages\CreateBillingTaxExemption;
use Liberu\Billing\Core\Filament\Resources\BillingTaxExemptionResource\Pages\EditBillingTaxExemption;
use Liberu\Billing\Core\Filament\Resources\BillingTaxExemptionResource\Pages\ListBillingTaxExemptions;
use Liberu\Billing\Core\Filament\Resources\BillingTaxProfileResource\Pages\CreateBillingTaxProfile;
use Liberu\Billing\Core\Filament\Resources\BillingTaxProfileResource\Pages\EditBillingTaxProfile;
use Liberu\Billing\Core\Filament\Resources\BillingTaxProfileResource\Pages\ListBillingTaxProfiles;
use Liberu\Billing\Core\Filament\Resources\BillingTermResource\Pages\CreateBillingTerm;
use Liberu\Billing\Core\Filament\Resources\BillingTermResource\Pages\EditBillingTerm;
use Liberu\Billing\Core\Filament\Resources\BillingTermResource\Pages\ListBillingTerms;
use Liberu\Billing\Core\Models\BillingAccount;
use Liberu\Billing\Core\Models\BillingContact;
use Liberu\Billing\Core\Models\BillingCurrency;
use Liberu\Billing\Core\Models\BillingSequence;
use Liberu\Billing\Core\Models\BillingSetting;
use Liberu\Billing\Core\Models\BillingTaxExemption;
use Liberu\Billing\Core\Models\BillingTaxProfile;
use Liberu\Billing\Core\Models\BillingTerm;
use Livewire\Livewire;

it('manages billing accounts and billing core resources through Filament', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $team = $actor->ownedTeams()->sole();
    $actor->switchTeam($team);
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.billing-core.read', 'billing.billing-core.write']);

    foreach ([
        BillingAccount::class,
        BillingContact::class,
        BillingCurrency::class,
        BillingSequence::class,
        BillingSetting::class,
        BillingTaxExemption::class,
        BillingTaxProfile::class,
        BillingTerm::class,
    ] as $modelClass) {
        $modelClass::resolveRelationUsing('team', fn ($model) => $model->belongsTo(Team::class, 'team_id'));
    }

    if (! class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
        class_alias(DeleteAction::class, 'Filament\Tables\Actions\DeleteAction');
    }

    BillingAccount::creating(function (BillingAccount $account): void {
        if (! array_key_exists('status', $account->getAttributes())) {
            $account->status = BillingAccountStatus::Active;
        }
    });

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);
    BillingCoreFilamentPlugin::make()->boot($panel);

    try {
        Livewire::test(CreateBillingAccount::class)
            ->fillForm([
                'name' => 'Primary Account',
                'currency' => 'USD',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = BillingAccount::query()->where('name', 'Primary Account')->sole();
        $account->update(['team_id' => $team->id]);

        Livewire::test(EditBillingAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['name' => 'Primary Account Updated', 'currency' => 'USD'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ListBillingAccounts::class)
            ->assertCanSeeTableRecords([$account])
            ->assertTableActionHasUrl('edit', BillingAccountResource::getUrl('edit', ['record' => $account]), $account)
            ->callTableAction('status', $account, data: ['status' => 'suspended']);

        expect($account->fresh()->status)->toBe(BillingAccountStatus::Suspended);

        // BillingContact
        Livewire::test(CreateBillingContact::class)
            ->fillForm(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '555-0100'])
            ->call('create')
            ->assertHasNoFormErrors();
        $contact = BillingContact::query()->where('name', 'Jane Doe')->sole();
        Livewire::test(ListBillingContacts::class)->assertCanSeeTableRecords([$contact]);
        Livewire::test(EditBillingContact::class, ['record' => $contact->getRouteKey()])
            ->fillForm(['name' => 'Jane Updated', 'email' => 'jane@example.com', 'phone' => '555-0101'])
            ->call('save')
            ->assertHasNoFormErrors();
        expect($contact->fresh()->name)->toBe('Jane Updated');

        // BillingCurrency + convert action
        Livewire::test(CreateBillingCurrency::class)
            ->fillForm(['code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2, 'enabled' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $usd = BillingCurrency::query()->where('code', 'USD')->sole();
        $usd->update(['exchange_rate' => 1.0]);

        BillingCurrency::query()->create([
            'team_id' => $team->id,
            'code' => 'EUR',
            'name' => 'Euro',
            'decimal_places' => 2,
            'exchange_rate' => 0.85,
            'enabled' => true,
        ]);

        Livewire::test(ListBillingCurrencies::class)
            ->assertCanSeeTableRecords([$usd])
            ->callTableAction('convert', $usd, data: ['amount' => 100, 'to' => 'EUR'])
            ->assertNotified();

        Livewire::test(EditBillingCurrency::class, ['record' => $usd->getRouteKey()])
            ->fillForm(['code' => 'USD', 'name' => 'US Dollars', 'decimal_places' => 2, 'enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        // BillingTaxProfile + calculate action
        Livewire::test(CreateBillingTaxProfile::class)
            ->fillForm(['name' => 'UK VAT', 'rate' => 20, 'jurisdiction' => 'GB', 'inclusive' => false, 'enabled' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $taxProfile = BillingTaxProfile::query()->where('name', 'UK VAT')->sole();

        Livewire::test(ListBillingTaxProfiles::class)
            ->assertCanSeeTableRecords([$taxProfile])
            ->callTableAction('calculate', $taxProfile, data: ['amount' => 100])
            ->assertNotified();

        Livewire::test(EditBillingTaxProfile::class, ['record' => $taxProfile->getRouteKey()])
            ->fillForm(['name' => 'UK VAT 20', 'rate' => 20, 'jurisdiction' => 'GB', 'inclusive' => false, 'enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        // BillingTaxExemption
        Livewire::test(CreateBillingTaxExemption::class)
            ->fillForm(['customer_id' => 42, 'reason' => 'Charity', 'enabled' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $exemption = BillingTaxExemption::query()->where('customer_id', 42)->sole();
        Livewire::test(ListBillingTaxExemptions::class)->assertCanSeeTableRecords([$exemption]);
        Livewire::test(EditBillingTaxExemption::class, ['record' => $exemption->getRouteKey()])
            ->fillForm(['customer_id' => 42, 'reason' => 'Updated Charity', 'enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        // BillingSequence
        Livewire::test(CreateBillingSequence::class)
            ->fillForm(['name' => 'Invoices', 'prefix' => 'INV-', 'next_number' => 1])
            ->call('create')
            ->assertHasNoFormErrors();
        $sequence = BillingSequence::query()->where('name', 'Invoices')->sole();
        Livewire::test(ListBillingSequences::class)->assertCanSeeTableRecords([$sequence]);
        Livewire::test(EditBillingSequence::class, ['record' => $sequence->getRouteKey()])
            ->fillForm(['name' => 'Invoices', 'prefix' => 'INV2-', 'next_number' => 2])
            ->call('save')
            ->assertHasNoFormErrors();

        // BillingTerm
        Livewire::test(CreateBillingTerm::class)
            ->fillForm(['name' => 'Net 30', 'due_days' => 30, 'default' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $term = BillingTerm::query()->where('name', 'Net 30')->sole();
        Livewire::test(ListBillingTerms::class)->assertCanSeeTableRecords([$term]);
        Livewire::test(EditBillingTerm::class, ['record' => $term->getRouteKey()])
            ->fillForm(['name' => 'Net 15', 'due_days' => 15, 'default' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        // BillingSetting
        Livewire::test(CreateBillingSetting::class)
            ->fillForm(['values' => ['invoice_footer' => 'Thank you']])
            ->call('create')
            ->assertHasNoFormErrors();
        $setting = BillingSetting::query()->where('team_id', $team->id)->sole();
        Livewire::test(ListBillingSettings::class)->assertCanSeeTableRecords([$setting]);
        Livewire::test(EditBillingSetting::class, ['record' => $setting->getRouteKey()])
            ->fillForm(['values' => ['invoice_footer' => 'Updated']])
            ->call('save')
            ->assertHasNoFormErrors();
    } finally {
        Filament::setTenant(null);
    }
});

it('falls back to default form and table definitions for unknown BillingCoreResource models', function (): void {
    $resourceClass = new class() extends BillingCoreResource
    {
        protected static ?string $model = BillingAccount::class;
    };

    $listComponent = new ListBillingContacts();
    $schema = $resourceClass::form(Schema::make($listComponent));
    $table = $resourceClass::table(Table::make($listComponent));

    expect($schema->getComponents())->toBe([])
        ->and($table->getColumns())->toHaveKey('id');
});
