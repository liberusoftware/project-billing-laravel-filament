<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Invoicing\Actions\AddInvoiceLine;
use Liberu\Billing\Invoicing\Actions\ApplyInvoiceAdjustment;
use Liberu\Billing\Invoicing\Actions\ApplyInvoiceLateFee;
use Liberu\Billing\Invoicing\Actions\CreateInvoice;
use Liberu\Billing\Invoicing\Actions\CreateInvoiceSchedule;
use Liberu\Billing\Invoicing\Actions\CreateInvoiceSupport;
use Liberu\Billing\Invoicing\Actions\CreatePaymentPlan;
use Liberu\Billing\Invoicing\Actions\DeliverInvoice;
use Liberu\Billing\Invoicing\Actions\FinalizeInvoice;
use Liberu\Billing\Invoicing\Actions\GenerateInvoiceDocument;
use Liberu\Billing\Invoicing\Actions\RunInvoiceSchedule;
use Liberu\Billing\Invoicing\Actions\RunPaymentPlan;
use Liberu\Billing\Invoicing\Enums\InvoiceStatus;
use Liberu\Billing\Invoicing\Models\Invoice;
use Liberu\Billing\Invoicing\Models\InvoiceSchedule;
use Liberu\Billing\Invoicing\Models\InvoiceSupport;
use Liberu\Billing\Invoicing\Policies\InvoicePolicy;
use Liberu\Billing\Invoicing\Policies\InvoiceSchedulePolicy;
use Liberu\Billing\Invoicing\Policies\InvoiceSupportPolicy;
use Liberu\Billing\Invoicing\Queries\ListInvoices;
use Liberu\Billing\Invoicing\Support\CustomerReference;

it('covers invoicing actions validation, edge cases, and happy paths', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();

    $createInvoice = app(CreateInvoice::class);
    $addLine = app(AddInvoiceLine::class);
    $finalize = app(FinalizeInvoice::class);
    $adjust = app(ApplyInvoiceAdjustment::class);
    $lateFee = app(ApplyInvoiceLateFee::class);
    $document = app(GenerateInvoiceDocument::class);
    $deliver = app(DeliverInvoice::class);
    $createSupport = app(CreateInvoiceSupport::class);

    $draft = $createInvoice->execute([
        'team_id' => $team->id,
        'currency' => 'USD',
        'due_at' => now()->subDays(5),
    ]);

    // AddInvoiceLine invalid arguments
    expect(fn () => $addLine->execute($draft, '   ', 1, 1000))
        ->toThrow(\InvalidArgumentException::class);

    // AddInvoiceLine stale status inside transaction
    $staleDraft = $createInvoice->execute(['team_id' => $team->id, 'currency' => 'USD']);
    Invoice::query()->whereKey($staleDraft->id)->update(['status' => InvoiceStatus::Finalized->value]);
    expect(fn () => $addLine->execute($staleDraft, 'Stale line', 1, 1000))
        ->toThrow(\LogicException::class);

    // FinalizeInvoice stale status inside transaction
    $staleFinalize = $createInvoice->execute(['team_id' => $team->id, 'currency' => 'USD']);
    $addLine->execute($staleFinalize, 'Item', 1, 1000);
    Invoice::query()->whereKey($staleFinalize->id)->update(['status' => InvoiceStatus::Finalized->value]);
    expect(fn () => $finalize->execute($staleFinalize))
        ->toThrow(\LogicException::class);

    // GenerateInvoiceDocument on draft throws
    expect(fn () => $document->execute($draft, 'pdf'))
        ->toThrow(\LogicException::class);

    // DeliverInvoice with invalid email throws
    expect(fn () => $deliver->execute($draft, 'not-an-email'))
        ->toThrow(\InvalidArgumentException::class);

    // ApplyInvoiceLateFee validation & non-finalized throws
    expect(fn () => $lateFee->execute($draft, 0))
        ->toThrow(\InvalidArgumentException::class);
    expect(fn () => $lateFee->execute($draft, 500))
        ->toThrow(\LogicException::class);

    // Add line and finalize draft
    $addLine->execute($draft, 'Platform Subscription', 2, 1000, 10.0);
    $finalized = $finalize->execute($draft->refresh());

    // GenerateInvoiceDocument invalid format throws, valid html succeeds
    expect(fn () => $document->execute($finalized, 'docx'))
        ->toThrow(\LogicException::class);
    $htmlDoc = $document->execute($finalized, 'html');
    expect($htmlDoc->type)->toBe('html');

    // ApplyInvoiceAdjustment invalid args and excessive negative adjustment
    expect(fn () => $adjust->execute($finalized, 0, 'Zero'))
        ->toThrow(\InvalidArgumentException::class);
    expect(fn () => $adjust->execute($finalized, -999999, 'Too large', 'credit'))
        ->toThrow(\LogicException::class);

    // ApplyInvoiceAdjustment on void invoice throws
    $voidInvoice = $createInvoice->execute(['team_id' => $team->id, 'currency' => 'USD']);
    $voidInvoice->update(['status' => InvoiceStatus::Void]);
    expect(fn () => $adjust->execute($voidInvoice, 100, 'Void check'))
        ->toThrow(\LogicException::class);

    // ApplyInvoiceLateFee succeeds and is idempotent on same date
    $withFee = $lateFee->execute($finalized, 250);
    expect($withFee->total_minor)->toBe($finalized->total_minor + 250);
    $sameDayFee = $lateFee->execute($withFee, 250);
    expect($sameDayFee->total_minor)->toBe($withFee->total_minor);

    // CreateInvoiceSupport happy paths (applied and pending) and invalid input
    $taxSupport = $createSupport->execute($team->id, [
        'invoice_id' => $finalized->id,
        'type' => 'tax',
        'amount_minor' => 200,
        'currency' => 'USD',
    ]);
    expect($taxSupport->status)->toBe('applied');

    $deliverySupport = $createSupport->execute($team->id, [
        'invoice_id' => $finalized->id,
        'type' => 'delivery',
        'destination' => 'billing@example.com',
    ]);
    expect($deliverySupport->status)->toBe('pending');

    expect(fn () => $createSupport->execute($team->id, [
        'invoice_id' => $finalized->id,
        'type' => 'unknown',
    ]))->toThrow(\InvalidArgumentException::class);

    // ListInvoices query
    $list = app(ListInvoices::class)->execute($team->id, 10);
    expect($list->total())->toBeGreaterThanOrEqual(1);
    expect(app(ListInvoices::class)->execute(null, 10)->total())->toBe(0);
});

it('covers invoice schedules and payment plans across all frequencies and error states', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();

    $createSchedule = app(CreateInvoiceSchedule::class);
    $runSchedule = app(RunInvoiceSchedule::class);
    $createInvoice = app(CreateInvoice::class);
    $addLine = app(AddInvoiceLine::class);
    $finalize = app(FinalizeInvoice::class);
    $createPlan = app(CreatePaymentPlan::class);
    $runPlan = app(RunPaymentPlan::class);

    // CreateInvoiceSchedule non-array metadata throws
    expect(fn () => $createSchedule->execute([
        'team_id' => $team->id,
        'frequency' => 'monthly',
        'metadata' => 'not-an-array',
    ]))->toThrow(\InvalidArgumentException::class);

    // Inactive schedule throws
    $inactive = $createSchedule->execute([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'next_run_at' => now()->subMinute(),
        'active' => false,
    ]);
    expect(fn () => $runSchedule->execute($inactive))
        ->toThrow(\LogicException::class);

    // Schedule with non-array line item throws
    $badLines = $createSchedule->execute([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'next_run_at' => now()->subMinute(),
        'metadata' => ['lines' => ['invalid-line']],
    ]);
    expect(fn () => $runSchedule->execute($badLines))
        ->toThrow(\InvalidArgumentException::class);

    // Daily, weekly, yearly schedules
    foreach (['daily', 'weekly', 'yearly'] as $frequency) {
        $schedule = $createSchedule->execute([
            'team_id' => $team->id,
            'frequency' => $frequency,
            'next_run_at' => now()->subMinute(),
            'metadata' => [
                'currency' => 'USD',
                'lines' => [['description' => "Recurring {$frequency}", 'quantity' => 1, 'unit_amount_minor' => 1000]],
            ],
        ]);
        $generated = $runSchedule->execute($schedule);
        expect($generated->status)->toBe(InvoiceStatus::Finalized);
    }

    // Invalid frequency stored directly in DB throws LogicException
    $brokenFreq = $createSchedule->execute([
        'team_id' => $team->id,
        'frequency' => 'monthly',
        'next_run_at' => now()->subMinute(),
    ]);
    InvoiceSchedule::query()->whereKey($brokenFreq->id)->update(['frequency' => 'invalid']);
    expect(fn () => $runSchedule->execute($brokenFreq->refresh()))
        ->toThrow(\LogicException::class);

    // CreatePaymentPlan validation errors
    $draft = $createInvoice->execute(['team_id' => $team->id, 'currency' => 'USD']);
    expect(fn () => $createPlan->execute($draft, 2, 'monthly'))
        ->toThrow(\InvalidArgumentException::class);

    // Installment amount < 1 throws
    $tiny = $createInvoice->execute(['team_id' => $team->id, 'currency' => 'USD']);
    $addLine->execute($tiny, 'Tiny', 1, 1);
    $tiny = $finalize->execute($tiny->refresh());
    expect(fn () => $createPlan->execute($tiny, 2, 'monthly'))
        ->toThrow(\InvalidArgumentException::class);

    // Weekly and quarterly payment plans + RunPaymentPlan errors and execution
    $invoice = $createInvoice->execute(['team_id' => $team->id, 'currency' => 'USD', 'number' => 'INV-PLAN']);
    $addLine->execute($invoice, 'Annual License', 1, 10000);
    $invoice = $finalize->execute($invoice->refresh());

    $weeklyPlan = $createPlan->execute($invoice, 2, 'weekly', now()->subWeeks(2));
    // Future check: next_due_at is after an earlier $at
    expect(fn () => $runPlan->execute($weeklyPlan, now()->subMonths(2)))
        ->toThrow(\LogicException::class);

    $inst1 = $runPlan->execute($weeklyPlan, now()->addMonth());
    expect($inst1->status)->toBe(InvoiceStatus::Finalized);
    $inst2 = $runPlan->execute($weeklyPlan->refresh(), now()->addMonths(2));
    expect($inst2->status)->toBe(InvoiceStatus::Finalized);
    expect($weeklyPlan->refresh()->status)->toBe('completed');

    // Completed plan throws
    expect(fn () => $runPlan->execute($weeklyPlan->refresh(), now()->addMonths(3)))
        ->toThrow(\LogicException::class);

    $quarterlyPlan = $createPlan->execute($invoice, 2, 'quarterly', now()->subMonths(6));
    $qInst = $runPlan->execute($quarterlyPlan, now());
    expect($qInst->status)->toBe(InvoiceStatus::Finalized);
});

it('covers invoicing policies and customer reference table guard', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);

    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->ownedTeams()->sole();
    $otherUser->switchTeam($otherTeam);

    Sanctum::actingAs($user, ['billing.invoicing.read', 'billing.invoicing.write']);
    Sanctum::actingAs($otherUser, []);

    $invoice = Invoice::query()->create([
        'team_id' => $team->id,
        'status' => InvoiceStatus::Draft,
        'currency' => 'USD',
        'subtotal_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
    ]);
    $schedule = InvoiceSchedule::query()->create([
        'team_id' => $team->id,
        'frequency' => 'monthly',
        'next_run_at' => now(),
        'active' => true,
        'metadata' => [],
    ]);
    $support = InvoiceSupport::query()->create([
        'team_id' => $team->id,
        'invoice_id' => $invoice->id,
        'type' => 'tax',
        'status' => 'applied',
        'amount_minor' => 0,
        'payload' => [],
    ]);

    $invoicePolicy = new InvoicePolicy();
    expect($invoicePolicy->viewAny(null))->toBeFalse()
        ->and($invoicePolicy->viewAny($user))->toBeTrue()
        ->and($invoicePolicy->create(null))->toBeFalse()
        ->and($invoicePolicy->create($user))->toBeTrue()
        ->and($invoicePolicy->view($user, $invoice))->toBeTrue()
        ->and($invoicePolicy->update($user, $invoice))->toBeTrue()
        ->and($invoicePolicy->view($otherUser, $invoice))->toBeFalse();

    $schedulePolicy = new InvoiceSchedulePolicy();
    expect($schedulePolicy->viewAny(null))->toBeFalse()
        ->and($schedulePolicy->viewAny($user))->toBeTrue()
        ->and($schedulePolicy->create($user))->toBeTrue()
        ->and($schedulePolicy->view($user, $schedule))->toBeTrue()
        ->and($schedulePolicy->update($user, $schedule))->toBeTrue()
        ->and($schedulePolicy->view($otherUser, $schedule))->toBeFalse()
        ->and($schedulePolicy->update($otherUser, $schedule))->toBeFalse();

    $supportPolicy = new InvoiceSupportPolicy();
    expect($supportPolicy->viewAny(null))->toBeFalse()
        ->and($supportPolicy->viewAny($user))->toBeTrue()
        ->and($supportPolicy->create($user))->toBeTrue()
        ->and($supportPolicy->view($user, $support))->toBeTrue()
        ->and($supportPolicy->view($otherUser, $support))->toBeFalse();

    // CustomerReference when customers table does not exist
    Schema::shouldReceive('hasTable')->with('customers')->once()->andReturn(false);
    expect(fn () => CustomerReference::assertBelongsToTeam(app('db'), 1, $team->id))
        ->toThrow(\InvalidArgumentException::class);
});
