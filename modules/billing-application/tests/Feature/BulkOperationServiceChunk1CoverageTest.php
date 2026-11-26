<?php

declare(strict_types=1);

use App\Models\BulkOperation;
use App\Models\Client;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Services\BulkOperationService;
use Illuminate\Support\Facades\File;

it('records failed items when individual invoice creation throws in processBulkInvoiceGeneration', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $customers = Customer::factory()->count(2)->create();

    $service = new BulkOperationService();
    $operation = $service->bulkGenerateInvoices(
        $customers->pluck('id')->all(),
        [
            'invoice_number' => 'INV-CHUNK1-DUP-001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total_amount' => 125.00,
            'status' => 'pending',
        ],
        $user->id,
        $team->id,
    );

    $service->processBulkInvoiceGeneration($operation);

    $operation->refresh();
    expect($operation->status)->toBe('completed')
        ->and($operation->total_items)->toBe(2)
        ->and($operation->processed_items)->toBe(1)
        ->and($operation->failed_items)->toBe(1)
        ->and($operation->completed_at)->not->toBeNull()
        ->and(Invoice::where('invoice_number', 'INV-CHUNK1-DUP-001')->count())->toBe(1);
});

it('marks bulk invoice generation as failed when an outer exception occurs', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $service = new BulkOperationService();
    $operation = $service->bulkGenerateInvoices([], [], $user->id, $team->id);

    $mockOperation = Mockery::mock($operation)->makePartial();
    $mockOperation->shouldReceive('markAsCompleted')
        ->once()
        ->andThrow(new Exception('Invoice bulk completion failed'));

    $service->processBulkInvoiceGeneration($mockOperation);

    $operation->refresh();
    expect($operation->status)->toBe('failed')
        ->and($operation->error_message)->toBe('Invoice bulk completion failed')
        ->and($operation->completed_at)->not->toBeNull();
});

it('exports clients filtered by team_id and creates the exports directory when missing', function (): void {
    $user = User::factory()->create();
    $teamA = Team::factory()->create(['user_id' => $user->id]);
    $teamB = Team::factory()->create(['user_id' => $user->id]);

    Client::create([
        'name' => 'Team A Client',
        'email' => 'team-a-chunk1@example.test',
        'phone' => '555-0100',
        'team_id' => $teamA->id,
    ]);
    Client::create([
        'name' => 'Team B Client',
        'email' => 'team-b-chunk1@example.test',
        'phone' => '555-0200',
        'team_id' => $teamB->id,
    ]);

    $exportsDir = storage_path('app/exports');
    File::deleteDirectory($exportsDir);
    expect(file_exists($exportsDir))->toBeFalse();

    $service = new BulkOperationService();
    $operation = $service->exportClients(['team_id' => $teamA->id], $user->id, $teamA->id);

    $operation->refresh();
    $filePath = storage_path('app/'.$operation->result_file);

    try {
        expect($operation->status)->toBe('completed')
            ->and($operation->total_items)->toBe(1)
            ->and($operation->processed_items)->toBe(1)
            ->and(file_exists($filePath))->toBeTrue();

        $contents = (string) file_get_contents($filePath);
        expect($contents)->toContain('team-a-chunk1@example.test')
            ->and($contents)->not->toContain('team-b-chunk1@example.test');
    } finally {
        if ($operation->result_file && file_exists($filePath)) {
            @unlink($filePath);
        }
    }
});

it('marks data export as failed when an outer exception occurs', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $operation = BulkOperation::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'type' => 'data_export',
        'parameters' => [
            'entity' => 'other',
            'filters' => [],
        ],
        'status' => 'pending',
    ]);

    $mockOperation = Mockery::mock($operation)->makePartial();
    $mockOperation->shouldReceive('markAsCompleted')
        ->once()
        ->andThrow(new Exception('Export completion failed'));

    $service = new BulkOperationService();
    $service->processDataExport($mockOperation);

    $operation->refresh();
    expect($operation->status)->toBe('failed')
        ->and($operation->error_message)->toBe('Export completion failed')
        ->and($operation->completed_at)->not->toBeNull();
});

it('imports clients from CSV and records failures for invalid or duplicate rows', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $tmpCsv = tempnam(sys_get_temp_dir(), 'chunk1_clients_import_');
    file_put_contents(
        $tmpCsv,
        "Name,Email,Phone\n".
        "Alice Import,alice-chunk1-import@example.test,555-1001\n".
        "Bob Import,bob-chunk1-import@example.test,555-1002\n".
        "Duplicate Alice,alice-chunk1-import@example.test,555-1003\n"
    );

    try {
        $service = new BulkOperationService();
        $operation = $service->importClients($tmpCsv, $user->id, $team->id);

        $operation->refresh();
        expect($operation->type)->toBe('client_import')
            ->and($operation->status)->toBe('completed')
            ->and($operation->total_items)->toBe(3)
            ->and($operation->processed_items)->toBe(2)
            ->and($operation->failed_items)->toBe(1)
            ->and($operation->completed_at)->not->toBeNull();

        expect(Client::where('email', 'alice-chunk1-import@example.test')->where('team_id', $team->id)->exists())->toBeTrue()
            ->and(Client::where('email', 'bob-chunk1-import@example.test')->where('team_id', $team->id)->exists())->toBeTrue();
    } finally {
        @unlink($tmpCsv);
    }
});

it('marks client import as failed when an outer exception occurs', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    $tmpCsv = tempnam(sys_get_temp_dir(), 'chunk1_clients_outer_fail_');
    file_put_contents($tmpCsv, "Name,Email,Phone\n");

    try {
        $operation = BulkOperation::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'type' => 'client_import',
            'parameters' => ['file_path' => $tmpCsv],
            'status' => 'pending',
        ]);

        $mockOperation = Mockery::mock($operation)->makePartial();
        $mockOperation->shouldReceive('markAsCompleted')
            ->once()
            ->andThrow(new Exception('Import completion failed'));

        $service = new BulkOperationService();
        $service->processClientImport($mockOperation);

        $operation->refresh();
        expect($operation->status)->toBe('failed')
            ->and($operation->error_message)->toBe('Import completion failed')
            ->and($operation->completed_at)->not->toBeNull();
    } finally {
        @unlink($tmpCsv);
    }
});

it('creates and sends an email campaign filtered by team_id and status', function (): void {
    $user = User::factory()->create();
    $teamA = Team::factory()->create(['user_id' => $user->id]);
    $teamB = Team::factory()->create(['user_id' => $user->id]);

    Client::create([
        'name' => 'Active Team A',
        'email' => 'active-a-chunk1@example.test',
        'team_id' => $teamA->id,
        'status' => 'active',
    ]);
    Client::create([
        'name' => 'Inactive Team A',
        'email' => 'inactive-a-chunk1@example.test',
        'team_id' => $teamA->id,
        'status' => 'inactive',
    ]);
    Client::create([
        'name' => 'Active Team B',
        'email' => 'active-b-chunk1@example.test',
        'team_id' => $teamB->id,
        'status' => 'active',
    ]);

    $service = new BulkOperationService();
    $campaign = $service->createEmailCampaign(
        'Chunk1 Promo',
        'Special Offer',
        '<p>Hello active clients</p>',
        ['team_id' => $teamA->id, 'status' => 'active'],
        $user->id,
        $teamA->id,
    );

    expect($campaign)->toBeInstanceOf(EmailCampaign::class)
        ->and($campaign->status)->toBe('draft')
        ->and($campaign->name)->toBe('Chunk1 Promo')
        ->and($campaign->subject)->toBe('Special Offer')
        ->and($campaign->created_by)->toBe($user->id)
        ->and($campaign->team_id)->toBe($teamA->id);

    $service->sendEmailCampaign($campaign);

    $campaign->refresh();
    expect($campaign->status)->toBe('sent')
        ->and($campaign->total_recipients)->toBe(1)
        ->and($campaign->sent_count)->toBe(1)
        ->and($campaign->failed_count)->toBe(0)
        ->and($campaign->started_sending_at)->not->toBeNull()
        ->and($campaign->completed_at)->not->toBeNull();

    // Unfiltered campaign targets all clients
    $allCampaign = $service->createEmailCampaign(
        'Chunk1 All Clients',
        'General Update',
        '<p>Hello everyone</p>',
        [],
        $user->id,
        $teamA->id,
    );
    $service->sendEmailCampaign($allCampaign);

    $allCampaign->refresh();
    expect($allCampaign->status)->toBe('sent')
        ->and($allCampaign->total_recipients)->toBe(3)
        ->and($allCampaign->sent_count)->toBe(3);
});

it('handles per-recipient and outer exceptions when sending an email campaign', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);

    Client::create([
        'name' => 'Recipient One',
        'email' => 'recipient-one-chunk1@example.test',
        'team_id' => $team->id,
        'status' => 'active',
    ]);

    $service = new BulkOperationService();

    // Inner exception: incrementSent throws, incrementFailed is recorded
    $innerCampaign = $service->createEmailCampaign(
        'Inner Failure Campaign',
        'Subject',
        '<p>Body</p>',
        ['team_id' => $team->id, 'status' => 'active'],
        $user->id,
        $team->id,
    );
    $innerMock = Mockery::mock($innerCampaign)->makePartial();
    $innerMock->shouldReceive('incrementSent')
        ->once()
        ->andThrow(new Exception('Mail transport failure'));

    $service->sendEmailCampaign($innerMock);

    $innerCampaign->refresh();
    expect($innerCampaign->status)->toBe('sent')
        ->and($innerCampaign->total_recipients)->toBe(1)
        ->and($innerCampaign->sent_count)->toBe(0)
        ->and($innerCampaign->failed_count)->toBe(1);

    // Outer exception: update throws, caught without rethrowing and markAsSent is skipped
    $outerCampaign = $service->createEmailCampaign(
        'Outer Failure Campaign',
        'Subject',
        '<p>Body</p>',
        ['team_id' => $team->id],
        $user->id,
        $team->id,
    );
    $outerMock = Mockery::mock($outerCampaign)->makePartial();
    $outerMock->shouldReceive('update')
        ->once()
        ->with(['total_recipients' => 1])
        ->andThrow(new Exception('Database update failure'));
    $outerMock->shouldNotReceive('markAsSent');

    $service->sendEmailCampaign($outerMock);

    $outerCampaign->refresh();
    expect($outerCampaign->status)->toBe('sending')
        ->and($outerCampaign->completed_at)->toBeNull();
});
