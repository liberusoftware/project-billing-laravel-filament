<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Invoicing\Actions\AddInvoiceLine;
use Liberu\Billing\Invoicing\Enums\InvoiceStatus;
use Liberu\Billing\Invoicing\Filament\InvoicingFilamentPlugin;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceLineResource;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceResource;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceResource\Pages\CreateInvoicePage;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceScheduleResource;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceScheduleResource\Pages\CreateInvoiceSchedule;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceScheduleResource\Pages\ListInvoiceSchedules;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceSupportResource;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceSupportResource\Pages\CreateInvoiceSupport;
use Liberu\Billing\Invoicing\Filament\Resources\InvoiceSupportResource\Pages\ListInvoiceSupports;
use Liberu\Billing\Invoicing\Filament\Resources\PaymentPlanResource;
use Liberu\Billing\Invoicing\Models\Invoice;
use Liberu\Billing\Invoicing\Models\InvoiceSchedule;
use Liberu\Billing\Invoicing\Models\InvoiceSupport;
use Liberu\Billing\Invoicing\Models\PaymentPlan;
use Livewire\Livewire;

it('manages invoices, schedules, support records, lines, and payment plans through Filament', function (): void {
    $actor = User::factory()->withPersonalTeam()->create();
    $team = $actor->ownedTeams()->sole();
    $actor->switchTeam($team);
    $this->actingAs($actor, 'web');
    Sanctum::actingAs($actor, ['billing.invoicing.read', 'billing.invoicing.write']);

    foreach ([
        Invoice::class,
        InvoiceSchedule::class,
        InvoiceSupport::class,
        PaymentPlan::class,
    ] as $modelClass) {
        $modelClass::resolveRelationUsing('team', fn ($model) => $model->belongsTo(Team::class, 'team_id', null, 'team'));
    }

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($team);
    InvoicingFilamentPlugin::make()->boot($panel);

    try {
        // Create invoice via Filament page
        Livewire::test(CreateInvoicePage::class)
            ->fillForm([
                'currency' => 'USD',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::query()->where('team_id', $team->id)->latest('id')->firstOrFail();
        app(AddInvoiceLine::class)->execute($invoice, 'Filament Service', 1, 5000);

        // InvoiceResource table actions: finalize, document, deliver, paymentPlan, lateFee
        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$invoice])
            ->callTableAction('finalize', $invoice)
            ->callTableAction('document', $invoice->refresh())
            ->callTableAction('deliver', $invoice->refresh(), data: ['destination' => 'filament@example.com'])
            ->callTableAction('paymentPlan', $invoice->refresh(), data: ['total_installments' => 2, 'frequency' => 'monthly']);

        $invoice->update(['due_at' => now()->subDays(5)]);

        Livewire::test(ListInvoices::class)
            ->callTableAction('lateFee', $invoice->refresh(), data: ['amount_minor' => 250]);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Finalized)
            ->and($invoice->fresh()->total_minor)->toBe(5250);

        // InvoiceScheduleResource create + list
        Livewire::test(CreateInvoiceSchedule::class)
            ->fillForm([
                'frequency' => 'monthly',
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $schedule = InvoiceSchedule::query()->where('team_id', $team->id)->latest('id')->firstOrFail();
        Livewire::test(ListInvoiceSchedules::class)
            ->assertCanSeeTableRecords([$schedule]);

        // InvoiceSupportResource create + list
        Livewire::test(CreateInvoiceSupport::class)
            ->fillForm([
                'invoice_id' => $invoice->id,
                'type' => 'tax',
                'amount_minor' => 100,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $support = InvoiceSupport::query()->where('invoice_id', $invoice->id)->where('type', 'tax')->latest('id')->firstOrFail();
        Livewire::test(ListInvoiceSupports::class)
            ->assertCanSeeTableRecords([$support]);

        // InvoiceLineResource table definition
        $listComponent = new ListInvoices();
        $lineTable = InvoiceLineResource::table(Table::make($listComponent));
        expect($lineTable->getColumns())->toHaveKey('description');

        // PaymentPlanResource table definition and run action
        $plan = PaymentPlan::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
        $plan->update(['next_due_at' => now()->subDay()]);

        $planTable = PaymentPlanResource::table(Table::make($listComponent));
        expect($planTable->getColumns())->toHaveKey('frequency');

        $runAction = collect($planTable->getRecordActions())->first();
        expect($runAction)->not->toBeNull();
        $runAction->record($plan);
        expect($runAction->isVisible())->toBeTrue();
        $runAction->call();

        expect($plan->fresh()->generated_installments)->toBe(1);
    } finally {
        Filament::setTenant(null);
    }
});

it('scopes Filament invoicing queries when no current team is active', function (): void {
    $invoice = Invoice::query()->create([
        'team_id' => 999,
        'status' => InvoiceStatus::Draft,
        'currency' => 'USD',
        'subtotal_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
    ]);
    InvoiceSchedule::query()->create([
        'team_id' => 999,
        'frequency' => 'monthly',
        'next_run_at' => now(),
        'active' => true,
        'metadata' => [],
    ]);
    InvoiceSupport::query()->create([
        'team_id' => 999,
        'invoice_id' => $invoice->id,
        'type' => 'tax',
        'status' => 'applied',
        'amount_minor' => 0,
        'payload' => [],
    ]);

    $this->actingAs(User::factory()->create(), 'web');

    expect(InvoiceResource::getEloquentQuery()->count())->toBe(0)
        ->and(InvoiceScheduleResource::getEloquentQuery()->count())->toBe(0)
        ->and(InvoiceSupportResource::getEloquentQuery()->count())->toBe(0);
});
