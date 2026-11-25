<?php

declare(strict_types=1);

use App\Filament\Client\Resources\InvoiceResource;
use App\Filament\Client\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Client\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceCheckoutService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);
    $panel = Filament::getPanel('client');
    Filament::setCurrentPanel($panel);
    $panel->boot();
});

it('covers client InvoiceResource table columns, filters, actions, canCreate, and eloquent query scoping', function (): void {
    expect(InvoiceResource::canCreate())->toBeFalse();

    $pending = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'pending',
        'currency' => 'USD',
        'total_amount' => 100.00,
        'invoice_number' => 'INV-CL-001',
    ]);
    $paid = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'paid',
        'currency' => 'USD',
        'total_amount' => 200.00,
        'invoice_number' => 'INV-CL-002',
    ]);
    $overdue = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'overdue',
        'currency' => 'USD',
        'total_amount' => 300.00,
        'invoice_number' => 'INV-CL-003',
    ]);
    $partiallyPaid = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'partially_paid',
        'currency' => 'USD',
        'total_amount' => 400.00,
        'invoice_number' => 'INV-CL-004',
    ]);
    $draft = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'draft',
        'currency' => 'USD',
        'total_amount' => 50.00,
        'invoice_number' => 'INV-CL-005',
    ]);

    // Foreign invoice must not appear in query
    $otherCustomer = Customer::factory()->create(['user_id' => User::factory()->create()->id]);
    $foreign = Invoice::factory()->create(['customer_id' => $otherCustomer->id]);

    $pdfMock = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdfMock->shouldReceive('output')->once()->andReturn('%PDF-1.4-bytes');
    Pdf::shouldReceive('loadView')
        ->once()
        ->with('invoices.pdf', Mockery::on(fn (array $data): bool => $data['invoice']->id === $pending->id))
        ->andReturn($pdfMock);

    Livewire::test(ListInvoices::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$pending, $paid, $overdue, $partiallyPaid, $draft])
        ->assertCanNotSeeTableRecords([$foreign])
        ->filterTable('status', 'paid')
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$pending])
        ->resetTableFilters()
        ->callAction(TestAction::make('download_pdf')->table($pending))
        ->assertFileDownloaded('invoice-INV-CL-001.pdf');
});

it('covers client ViewInvoice form schema and paddleCheckout header action success and failure', function (): void {
    config()->set('services.paddle.webhook_gateway_id', 99);

    // Partially paid invoice exercises remaining_amount field and payment_amount validation rule closure
    $partiallyPaid = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'partially_paid',
        'currency' => 'USD',
        'total_amount' => 250.00,
    ]);

    $partialTest = Livewire::test(ViewInvoice::class, ['record' => $partiallyPaid->getRouteKey()])
        ->assertSuccessful()
        ->assertFormFieldExists('invoice_number')
        ->assertFormFieldExists('remaining_amount')
        ->assertFormFieldExists('payment_method')
        ->assertFormFieldExists('payment_amount');

    $fields = $partialTest->instance()->form->getFlatFields();
    expect($fields['payment_amount']->getValidationRules())->toContain('max:250');

    // Paid invoice hides the payment section
    $paid = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'paid',
        'currency' => 'USD',
        'total_amount' => 100.00,
    ]);

    Livewire::test(ViewInvoice::class, ['record' => $paid->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('paddleCheckout');

    // Pending invoice with paddleCheckout success redirect
    $pending = Invoice::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'pending',
        'currency' => 'USD',
        'total_amount' => 100.00,
    ]);

    $this->mock(InvoiceCheckoutService::class)
        ->shouldReceive('paddleCheckoutUrl')
        ->once()
        ->andReturn('https://checkout.paddle.com/client-ok');

    Livewire::test(ViewInvoice::class, ['record' => $pending->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('paddleCheckout')
        ->callAction('paddleCheckout')
        ->assertRedirect('https://checkout.paddle.com/client-ok');

    // Pending invoice with paddleCheckout failure notification
    $this->mock(InvoiceCheckoutService::class)
        ->shouldReceive('paddleCheckoutUrl')
        ->once()
        ->andThrow(new RuntimeException('Provider error'));

    Livewire::test(ViewInvoice::class, ['record' => $pending->getRouteKey()])
        ->assertSuccessful()
        ->callAction('paddleCheckout')
        ->assertNotified('Unable to open checkout');
});
