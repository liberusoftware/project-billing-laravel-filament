<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Invoicing\Models\Invoice;
use Liberu\Billing\Invoicing\Models\PaymentPlan;

it('manages invoices, schedules, payment plans, and invoice support through the invoicing API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->sole();
    $user->switchTeam($team);
    Sanctum::actingAs($user, ['billing.invoicing.read', 'billing.invoicing.write']);

    // Create draft invoice (overdue so late-fee endpoint works after finalization)
    $invoiceId = $this->postJson(route('billing.invoicing.store'), [
        'currency' => 'USD',
        'due_at' => now()->subDays(5)->toIso8601String(),
        'metadata' => ['source' => 'api'],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-invoices')
        ->assertJsonPath('data.attributes.currency', 'USD')
        ->json('data.id');

    // List invoices
    $this->getJson(route('billing.invoicing.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $invoiceId)
        ->assertJsonPath('meta.current_page', 1);

    // Add line
    $this->postJson(route('billing.invoicing.line', ['invoice' => $invoiceId]), [
        'description' => 'Monthly Hosting',
        'quantity' => 2,
        'unit_amount_minor' => 2500,
        'tax_rate' => 10,
    ])->assertOk()
        ->assertJsonPath('data.attributes.subtotal_minor', 5000);

    // Finalize invoice
    $this->postJson(route('billing.invoicing.finalize', ['invoice' => $invoiceId]))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'finalized');

    // Apply credit adjustment (covers credit branch)
    $this->postJson(route('billing.invoicing.adjust', ['invoice' => $invoiceId]), [
        'amount_minor' => 500,
        'reason' => 'Loyalty credit',
        'type' => 'credit',
    ])->assertOk()
        ->assertJsonPath('data.attributes.total_minor', 5000);

    // Apply late fee
    $this->postJson(route('billing.invoicing.late-fee', ['invoice' => $invoiceId]), [
        'amount_minor' => 250,
    ])->assertOk()
        ->assertJsonPath('data.attributes.total_minor', 5250);

    // Generate document
    $docId = $this->postJson(route('billing.invoicing.document', ['invoice' => $invoiceId]))
        ->assertOk()
        ->json('data.id');

    // Deliver invoice
    $this->postJson(route('billing.invoicing.deliver', ['invoice' => $invoiceId]), [
        'destination' => 'client@example.com',
        'document_id' => $docId,
    ])->assertCreated();

    // Create and run invoice schedule
    $scheduleId = $this->postJson(route('billing.invoicing.schedule'), [
        'frequency' => 'monthly',
        'next_run_at' => now()->subMinute()->toIso8601String(),
        'active' => true,
        'metadata' => [
            'currency' => 'USD',
            'lines' => [['description' => 'Scheduled fee', 'quantity' => 1, 'unit_amount_minor' => 1500]],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-invoice-schedules')
        ->json('data.id');

    $this->postJson(route('billing.invoicing.schedule.run', ['schedule' => $scheduleId]))
        ->assertOk()
        ->assertJsonPath('data.type', 'billing-invoices')
        ->assertJsonPath('data.attributes.status', 'finalized');

    // Create and run payment plan
    $planId = $this->postJson(route('billing.invoicing.payment-plan.create'), [
        'invoice_id' => (int) $invoiceId,
        'total_installments' => 2,
        'frequency' => 'monthly',
        'start_at' => now()->subMonths(2)->toIso8601String(),
    ])->assertCreated()
        ->assertJsonPath('data.type', 'billing-payment-plans')
        ->json('data.id');

    PaymentPlan::query()->whereKey($planId)->update(['next_due_at' => now()->subDay()]);

    $this->postJson(route('billing.invoicing.payment-plan.run', ['paymentPlan' => $planId]))
        ->assertOk()
        ->assertJsonPath('data.type', 'billing-invoices')
        ->assertJsonPath('data.attributes.status', 'finalized');

    // Create and list invoice support records
    $supportId = $this->postJson(route('billing.invoicing.support.store'), [
        'invoice_id' => (int) $invoiceId,
        'type' => 'tax',
        'amount_minor' => 100,
        'currency' => 'USD',
        'payload' => ['rate' => 10],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'invoice-support')
        ->json('data.id');

    $supportResponse = $this->getJson(route('billing.invoicing.support.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 10);

    expect(collect($supportResponse->json('data'))->pluck('id'))->toContain($supportId);

    expect(Invoice::query()->whereKey($invoiceId)->exists())->toBeTrue();
});
