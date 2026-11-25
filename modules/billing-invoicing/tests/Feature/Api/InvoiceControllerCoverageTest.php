<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->teamId = $this->user->currentTeam->id;
    $this->user->forceFill(['current_team_id' => $this->teamId])->save();
    Gate::before(fn (): true => true);
    Sanctum::actingAs($this->user, ['*']);
});

it('covers API invoice update for paid rejection, valid update, and cross-team 404', function (): void {
    $paidInvoice = Invoice::factory()->create([
        'team_id' => $this->teamId,
        'status' => 'paid',
    ]);

    $this->putJson("/api/invoices/{$paidInvoice->id}", ['status' => 'sent'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot update a paid invoice');

    $pendingInvoice = Invoice::factory()->create([
        'team_id' => $this->teamId,
        'status' => 'pending',
    ]);

    $issueDate = now()->toDateString();
    $dueDate = now()->addDays(14)->toDateString();

    $this->patchJson("/api/invoices/{$pendingInvoice->id}", [
        'issue_date' => $issueDate,
        'due_date' => $dueDate,
        'status' => 'sent',
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $pendingInvoice->id);

    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignInvoice = Invoice::factory()->create(['team_id' => $otherTeam->id]);

    $this->putJson("/api/invoices/{$foreignInvoice->id}", ['status' => 'sent'])
        ->assertNotFound();
});

it('covers API invoice destroy for non-draft rejection, draft deletion, and cross-team 404', function (): void {
    $pendingInvoice = Invoice::factory()->create([
        'team_id' => $this->teamId,
        'status' => 'pending',
    ]);

    $this->deleteJson("/api/invoices/{$pendingInvoice->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only draft invoices can be deleted');

    $draftInvoice = Invoice::factory()->create([
        'team_id' => $this->teamId,
        'status' => 'draft',
    ]);

    $this->deleteJson("/api/invoices/{$draftInvoice->id}")
        ->assertNoContent();

    expect(Invoice::query()->find($draftInvoice->id))->toBeNull();

    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignInvoice = Invoice::factory()->create([
        'team_id' => $otherTeam->id,
        'status' => 'draft',
    ]);

    $this->deleteJson("/api/invoices/{$foreignInvoice->id}")
        ->assertNotFound();
});

it('covers API invoice download for own team and cross-team 404', function (): void {
    $invoice = Invoice::factory()->create([
        'team_id' => $this->teamId,
        'invoice_number' => 'INV-DL-001',
    ]);

    $pdfMock = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdfMock->shouldReceive('download')
        ->once()
        ->with('invoice-INV-DL-001.pdf')
        ->andReturn(response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));

    Pdf::shouldReceive('loadView')
        ->once()
        ->with('invoices.pdf', Mockery::on(fn (array $data): bool => isset($data['invoice']) && $data['invoice']->id === $invoice->id))
        ->andReturn($pdfMock);

    $this->get("/api/invoices/{$invoice->id}/download")
        ->assertOk();

    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignInvoice = Invoice::factory()->create(['team_id' => $otherTeam->id]);

    $this->get("/api/invoices/{$foreignInvoice->id}/download")
        ->assertNotFound();
});
