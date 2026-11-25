<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Invoice_Item;
use App\Models\Payment;
use App\Models\Products_Service;
use App\Models\Report;
use App\Models\Team;
use App\Services\BillingService;
use App\Services\ClientBillingReportService;
use App\Services\ReportExportService;
use App\Services\ReportGenerationService;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function registerSqliteDateFormat(): void
{
    $pdo = DB::connection()->getPdo();
    if (method_exists($pdo, 'sqliteCreateFunction')) {
        $pdo->sqliteCreateFunction('DATE_FORMAT', function (?string $date, string $format): ?string {
            if ($date === null) {
                return null;
            }
            $ts = strtotime($date);
            if ($ts === false) {
                return null;
            }

            return date(str_replace(['%Y', '%m', '%d'], ['Y', 'm', 'd'], $format), $ts);
        }, 2);
    }
}

it('casts Report attributes and resolves team relation', function (): void {
    $team = Team::factory()->create();
    $report = Report::create([
        'name' => 'Monthly Revenue',
        'type' => 'revenue',
        'start_date' => '2026-01-01 00:00:00',
        'end_date' => '2026-01-31 23:59:59',
        'filters' => ['currency' => 'USD'],
        'format' => 'csv',
        'parameters' => ['customer_id' => 1],
        'schedule' => ['frequency' => 'monthly'],
        'last_generated_at' => '2026-02-01 00:00:00',
        'team_id' => $team->id,
    ]);

    $fresh = $report->fresh();

    expect($fresh->start_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($fresh->end_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($fresh->last_generated_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($fresh->filters)->toBe(['currency' => 'USD'])
        ->and($fresh->parameters)->toBe(['customer_id' => 1])
        ->and($fresh->schedule)->toBe(['frequency' => 'monthly'])
        ->and($fresh->team->id)->toBe($team->id);
});

it('filters billing history by end date and computes payment trends', function (): void {
    registerSqliteDateFormat();

    $customer = Customer::factory()->create();
    $inv1 = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-EARLY',
        'created_at' => '2026-02-01 10:00:00',
        'due_date' => '2026-02-15',
    ]);
    Invoice::factory()->create([
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-LATE',
        'created_at' => '2026-08-01 10:00:00',
        'due_date' => '2026-08-15',
    ]);
    Payment::factory()->create([
        'invoice_id' => $inv1->id,
        'amount' => 125.50,
        'created_at' => '2026-02-05 10:00:00',
    ]);

    $service = new ClientBillingReportService();
    $history = $service->generateBillingHistory($customer, '2026-01-01', '2026-03-01');
    expect($history)->toHaveCount(1)
        ->and($history->first()['invoice_number'])->toBe('INV-EARLY');

    $trends = $service->getPaymentTrends($customer);
    expect($trends)->toHaveCount(1)
        ->and($trends->first()->month)->toBe('2026-02')
        ->and((float) $trends->first()->total_paid)->toBe(125.5);
});

it('generates filtered revenue, outstanding balance, and service reports via ReportService', function (): void {
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $product = Products_Service::factory()->create(['name' => 'Cloud VPS']);

    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'total_amount' => 250,
        'status' => 'pending',
        'currency' => 'USD',
        'due_date' => now()->subDays(5),
    ]);
    Invoice_Item::factory()->create([
        'invoice_id' => $invoice->id,
        'product_service_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 250,
        'total_price' => 250,
        'created_at' => '2026-06-15 10:00:00',
    ]);
    Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 100,
        'currency' => 'USD',
        'created_at' => '2026-06-15 12:00:00',
    ]);

    Invoice::factory()->create([
        'customer_id' => $otherCustomer->id,
        'total_amount' => 500,
        'status' => 'pending',
        'currency' => 'USD',
        'due_date' => now()->subDays(5),
    ]);

    $service = app(ReportService::class);

    $revenue = $service->generateRevenueReport('2026-06-01 00:00:00', '2026-06-30 23:59:59', [
        'customer_id' => $customer->id,
        'service_id' => $product->id,
    ]);
    expect($revenue)->toHaveCount(1)
        ->and((float) $revenue->first()->total)->toBe(100.0);

    $outstanding = $service->generateOutstandingBalanceReport(['customer_id' => $customer->id]);
    expect($outstanding)->toHaveCount(1)
        ->and((float) $outstanding->first()->total_outstanding)->toBe(250.0);

    $serviceReport = $service->generateServiceReport('2026-06-01 00:00:00', '2026-06-30 23:59:59');
    $row = $serviceReport->firstWhere('id', $product->id);
    expect($row)->not->toBeNull()
        ->and($row->invoice_items_count)->toBe(1)
        ->and((float) $row->invoice_items_sum_total_price)->toBe(250.0);
});

it('exports revenue, outstanding, and service reports to CSV and PDF via ReportExportService', function (): void {
    Storage::fake();

    $reportService = Mockery::mock(ReportService::class);
    $reportService->shouldReceive('generateRevenueReport')->once()->andReturn([
        ['date' => '2026-06-15', 'total' => 100, 'currency' => 'USD'],
    ]);
    $reportService->shouldReceive('generateOutstandingBalanceReport')->once()->andReturn([
        ['customer_id' => 1, 'total_outstanding' => 250, 'currency' => 'USD'],
    ]);
    $reportService->shouldReceive('generateServiceReport')->once()->andReturn([
        ['id' => 1, 'name' => 'VPS'],
    ]);

    $pdfMock = Mockery::mock();
    $pdfMock->shouldReceive('output')->once()->andReturn('%PDF-1.4 fake');
    Pdf::shouldReceive('loadView')->once()->andReturn($pdfMock);

    $exporter = new ReportExportService($reportService);

    $revenueReport = new Report(['type' => 'revenue', 'start_date' => now()->subMonth(), 'end_date' => now(), 'filters' => []]);
    $csvFile = $exporter->exportToCsv($revenueReport);
    expect(Storage::exists("reports/{$csvFile}"))->toBeTrue();

    $outstandingReport = new Report(['type' => 'outstanding', 'filters' => []]);
    $pdfFile = $exporter->exportToPdf($outstandingReport);
    expect(Storage::exists("reports/{$pdfFile}"))->toBeTrue();

    $serviceReport = new Report(['type' => 'service', 'start_date' => now()->subMonth(), 'end_date' => now(), 'filters' => []]);
    $serviceCsv = $exporter->exportToCsv($serviceReport);
    expect(Storage::exists("reports/{$serviceCsv}"))->toBeTrue();

    $invalidReport = new Report(['type' => 'unknown']);
    expect(fn () => $exporter->exportToCsv($invalidReport))->toThrow(InvalidArgumentException::class);
});

it('generates PDF, CSV, Excel, and client_billing reports via ReportGenerationService', function (): void {
    registerSqliteDateFormat();
    Storage::fake();
    Storage::makeDirectory('reports');

    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'total_amount' => 150, 'due_date' => now()->addDays(10)]);
    Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 75]);

    $billingService = Mockery::mock(BillingService::class);
    $billingService->shouldReceive('getBillingSummary')->once()->andReturn([
        ['metric' => 'total', 'value' => 100],
    ]);
    $billingService->shouldReceive('getRevenueReport')->once()->andReturn([
        ['month' => '2026-06', 'revenue' => 200],
    ]);
    $billingService->shouldReceive('getCustomerActivityReport')->once()->andReturn([
        ['customer' => 'Alice', 'invoices' => 3],
    ]);

    $pdfMock = Mockery::mock();
    $pdfMock->shouldReceive('output')->twice()->andReturn('%PDF-1.4 content');
    Pdf::shouldReceive('loadView')->twice()->andReturn($pdfMock);

    $generator = new ReportGenerationService($billingService);

    $csvReport = new Report(['type' => 'billing_summary', 'format' => 'csv', 'parameters' => []]);
    $csvReport->id = 1;
    expect($generator->generateReport($csvReport))->toBe('report_1.csv');

    $excelReport = new Report(['type' => 'revenue_report', 'format' => 'excel', 'parameters' => []]);
    $excelReport->id = 2;
    expect($generator->generateReport($excelReport))->toBe('report_2.xlsx');

    $pdfReport = new Report(['type' => 'customer_activity', 'format' => 'pdf', 'parameters' => []]);
    $pdfReport->id = 3;
    expect($generator->generateReport($pdfReport))->toBe('report_3.pdf');

    $clientReport = new Report([
        'type' => 'client_billing',
        'format' => 'pdf',
        'parameters' => ['customer_id' => $customer->id],
    ]);
    $clientReport->id = 4;
    expect($generator->generateReport($clientReport))->toBe('report_4.pdf');

    $badFormat = new Report(['type' => 'client_billing', 'format' => 'xml', 'parameters' => ['customer_id' => $customer->id]]);
    expect(fn () => $generator->generateReport($badFormat))->toThrow(Exception::class, 'Unsupported report format');

    $badType = new Report(['type' => 'invalid_type', 'format' => 'csv', 'parameters' => []]);
    expect(fn () => $generator->generateReport($badType))->toThrow(Exception::class, 'Unsupported report type');
});
