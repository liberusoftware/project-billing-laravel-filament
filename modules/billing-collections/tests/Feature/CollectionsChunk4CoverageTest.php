<?php

declare(strict_types=1);

use App\Console\Commands\ProcessInvoiceReminders;
use App\Console\Commands\SuspendOverdueServices;
use App\Http\Controllers\InvoiceDisputeController;
use App\Mail\UpcomingInvoiceReminder;
use App\Models\Customer;
use App\Models\DisputeMessage;
use App\Models\Invoice;
use App\Models\InvoiceDispute;
use App\Models\LateFeeConfiguration;
use App\Models\Products_Service;
use App\Models\ReminderSetting;
use App\Models\ServiceSuspension;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CurrencyService;
use App\Services\DisputeService;
use App\Services\ServiceAutomationService;
use App\Services\ServiceProvisioningService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('executes all paths of ProcessInvoiceReminders command', function (): void {
    $provisioning = Mockery::mock(ServiceProvisioningService::class);
    $currency = Mockery::mock(CurrencyService::class);

    $command = new ProcessInvoiceReminders($provisioning, $currency);
    $command->setLaravel($this->app);

    $billingProp = new ReflectionProperty(ProcessInvoiceReminders::class, 'billingService');
    expect($billingProp->getValue($command))->toBeInstanceOf(BillingService::class);

    // 1. Lock already held
    Cache::put('processing_invoice_reminders', true, 60);
    $lockedOutput = new BufferedOutput();
    $lockedExit = $command->run(new ArrayInput([]), $lockedOutput);

    expect($lockedExit)->toBe(Command::FAILURE)
        ->and($lockedOutput->fetch())->toContain('Invoice reminder processing is already running');
    Cache::forget('processing_invoice_reminders');

    // 2. Success path
    $billingMock = Mockery::mock(BillingService::class);
    $billingMock->shouldReceive('sendUpcomingInvoiceReminders')->once()->andReturn(2);
    $billingMock->shouldReceive('sendOverdueReminders')->once()->andReturn(3);
    $billingProp->setValue($command, $billingMock);

    $successOutput = new BufferedOutput();
    $successExit = $command->run(new ArrayInput([]), $successOutput);
    $successText = $successOutput->fetch();

    expect($successExit)->toBe(Command::SUCCESS)
        ->and($successText)->toContain('Processing invoice reminders...')
        ->and($successText)->toContain('Sent 2 upcoming invoice reminders')
        ->and($successText)->toContain('Sent 3 overdue invoice reminders')
        ->and(Cache::get('processing_invoice_reminders'))->toBeNull();

    // 3. Exception path
    $failingMock = Mockery::mock(BillingService::class);
    $failingMock->shouldReceive('sendUpcomingInvoiceReminders')->once()->andThrow(new Exception('Mail service down'));
    $billingProp->setValue($command, $failingMock);

    $errorOutput = new BufferedOutput();
    $errorExit = $command->run(new ArrayInput([]), $errorOutput);

    expect($errorExit)->toBe(Command::FAILURE)
        ->and($errorOutput->fetch())->toContain('Error processing reminders: Mail service down')
        ->and(Cache::get('processing_invoice_reminders'))->toBeNull();
});

it('executes success and failure paths of SendInvoiceReminders command', function (): void {
    $billingMock = Mockery::mock(BillingService::class);
    $billingMock->shouldReceive('sendUpcomingDueReminders')->once();
    $billingMock->shouldReceive('sendOverdueReminders')->once()->andReturn(1);
    $this->app->instance(BillingService::class, $billingMock);

    $this->artisan('invoices:send-reminders')
        ->expectsOutput('Sending invoice reminders...')
        ->expectsOutput('Upcoming due date reminders sent successfully.')
        ->expectsOutput('Overdue reminders sent successfully.')
        ->assertExitCode(0);

    $failingMock = Mockery::mock(BillingService::class);
    $failingMock->shouldReceive('sendUpcomingDueReminders')->once()->andThrow(new Exception('SMS gateway offline'));
    $this->app->instance(BillingService::class, $failingMock);

    $this->artisan('invoices:send-reminders')
        ->expectsOutput('Sending invoice reminders...')
        ->expectsOutput('Error sending reminders: SMS gateway offline')
        ->assertExitCode(1);
});

it('executes all paths of SuspendOverdueServices command', function (): void {
    // 1. Lock already held
    $idleMock = Mockery::mock(ServiceAutomationService::class);
    $command = new SuspendOverdueServices($idleMock);
    $command->setLaravel($this->app);

    Cache::put('suspending_overdue_services', true, 60);
    $lockedOutput = new BufferedOutput();
    $lockedExit = $command->run(new ArrayInput(['--days' => '10']), $lockedOutput);

    expect($lockedExit)->toBe(Command::FAILURE)
        ->and($lockedOutput->fetch())->toContain('Service suspension is already running');
    Cache::forget('suspending_overdue_services');

    // 2. Success path with explicit --days option
    $successMock = Mockery::mock(ServiceAutomationService::class);
    $successMock->shouldReceive('suspendOverdueServices')->with(10)->once()->andReturn(4);
    $successCommand = new SuspendOverdueServices($successMock);
    $successCommand->setLaravel($this->app);

    $successOutput = new BufferedOutput();
    $successExit = $successCommand->run(new ArrayInput(['--days' => '10']), $successOutput);
    $successText = $successOutput->fetch();

    expect($successExit)->toBe(Command::SUCCESS)
        ->and($successText)->toContain('Suspending services with invoices overdue by 10 days...')
        ->and($successText)->toContain('Suspended 4 services')
        ->and(Cache::get('suspending_overdue_services'))->toBeNull();

    // 3. Exception path with default --days=7
    $failingMock = Mockery::mock(ServiceAutomationService::class);
    $failingMock->shouldReceive('suspendOverdueServices')->with(7)->once()->andThrow(new Exception('Provisioning error'));
    $failingCommand = new SuspendOverdueServices($failingMock);
    $failingCommand->setLaravel($this->app);

    $errorOutput = new BufferedOutput();
    $errorExit = $failingCommand->run(new ArrayInput([]), $errorOutput);

    expect($errorExit)->toBe(Command::FAILURE)
        ->and($errorOutput->fetch())->toContain('Error suspending services: Provisioning error')
        ->and(Cache::get('suspending_overdue_services'))->toBeNull();
});

it('handles store, update, and addMessage endpoints in InvoiceDisputeController', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $invoice = Invoice::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
    ]);

    $dispute = InvoiceDispute::create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'status' => 'open',
        'reason' => 'Duplicate charge',
        'description' => 'Charged twice for the same cycle',
    ]);

    $message = DisputeMessage::create([
        'invoice_dispute_id' => $dispute->id,
        'user_id' => $user->id,
        'message' => 'Here is the receipt',
        'attachments' => ['receipt.pdf'],
    ]);

    Route::middleware(SubstituteBindings::class)->group(function (): void {
        Route::post('/test-chunk4/invoices/{invoice}/disputes', [InvoiceDisputeController::class, 'store']);
        Route::patch('/test-chunk4/disputes/{dispute}', [InvoiceDisputeController::class, 'update']);
        Route::post('/test-chunk4/disputes/{dispute}/messages', [InvoiceDisputeController::class, 'addMessage']);
    });

    $disputeService = Mockery::mock(DisputeService::class);
    $disputeService->shouldReceive('createDispute')
        ->once()
        ->with(
            Mockery::on(fn (Invoice $i): bool => $i->is($invoice)),
            [
                'reason' => 'Duplicate charge',
                'description' => 'Charged twice for the same cycle',
            ]
        )
        ->andReturn($dispute);

    $disputeService->shouldReceive('updateDisputeStatus')
        ->once()
        ->with(
            Mockery::on(fn (InvoiceDispute $d): bool => $d->is($dispute)),
            'resolved',
            'Refunded duplicate charge'
        )
        ->andReturn($dispute);

    $disputeService->shouldReceive('updateDisputeStatus')
        ->once()
        ->with(
            Mockery::on(fn (InvoiceDispute $d): bool => $d->is($dispute)),
            'under_review',
            null
        )
        ->andReturn($dispute);

    $disputeService->shouldReceive('addMessage')
        ->once()
        ->with(
            Mockery::on(fn (InvoiceDispute $d): bool => $d->is($dispute)),
            [
                'message' => 'Here is the receipt',
                'attachments' => ['receipt.pdf'],
            ]
        )
        ->andReturn($message);

    $this->app->instance(DisputeService::class, $disputeService);

    $this->actingAs($user);

    $this->postJson("/test-chunk4/invoices/{$invoice->id}/disputes", [
        'reason' => 'Duplicate charge',
        'description' => 'Charged twice for the same cycle',
    ])
        ->assertCreated()
        ->assertJsonPath('id', $dispute->id);

    $this->patchJson("/test-chunk4/disputes/{$dispute->id}", [
        'status' => 'resolved',
        'resolution_notes' => 'Refunded duplicate charge',
    ])
        ->assertOk()
        ->assertJsonPath('id', $dispute->id);

    $this->patchJson("/test-chunk4/disputes/{$dispute->id}", [
        'status' => 'under_review',
    ])
        ->assertOk()
        ->assertJsonPath('id', $dispute->id);

    $this->postJson("/test-chunk4/disputes/{$dispute->id}/messages", [
        'message' => 'Here is the receipt',
        'attachments' => ['receipt.pdf'],
    ])
        ->assertCreated()
        ->assertJsonPath('id', $message->id);
});

it('builds UpcomingInvoiceReminder mailable with parsed subject, view, and escaped body', function (): void {
    $template = (object) [
        'subject' => 'Invoice {{invoice_number}} due soon',
        'body' => 'Hello {{customer_name}}, invoice {{invoice_number}} is due.',
    ];
    $data = [
        'invoice_number' => 'INV-2026-01',
        'customer_name' => 'Jane <Admin>',
    ];

    $mailable = new UpcomingInvoiceReminder($data, $template);
    $built = $mailable->build();

    expect($built)->toBe($mailable)
        ->and($built->subject)->toBe('Invoice INV-2026-01 due soon')
        ->and($built->view)->toBe('emails.upcoming-invoice-reminder')
        ->and($built->viewData['content'])->toBe('Hello Jane &lt;Admin&gt;, invoice INV-2026-01 is due.')
        ->and($built->viewData['data'])->toBe($data);
});

it('resolves relations on InvoiceDispute and DisputeMessage models', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $invoice = Invoice::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
    ]);

    $dispute = InvoiceDispute::create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'status' => 'resolved',
        'reason' => 'Billing error',
        'description' => 'Incorrect line item',
        'resolved_by' => $user->id,
    ]);

    expect($dispute->resolver())->toBeInstanceOf(BelongsTo::class)
        ->and($dispute->resolver->is($user))->toBeTrue();

    $message = DisputeMessage::create([
        'invoice_dispute_id' => $dispute->id,
        'user_id' => $user->id,
        'message' => 'Credit applied',
        'attachments' => ['credit-note.pdf'],
    ]);

    expect($message->invoiceDispute())->toBeInstanceOf(BelongsTo::class)
        ->and($message->user())->toBeInstanceOf(BelongsTo::class)
        ->and($message->invoiceDispute->is($dispute))->toBeTrue()
        ->and($message->user->is($user))->toBeTrue()
        ->and($message->fresh()->attachments)->toBe(['credit-note.pdf']);
});

it('exposes options and enforces validation rules on LateFeeConfiguration', function (): void {
    expect(LateFeeConfiguration::getFrequencyOptions())->toBe([
        'one-time' => 'One Time',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ]);

    expect(LateFeeConfiguration::getFeeTypeOptions())->toBe([
        'percentage' => 'Percentage',
        'fixed' => 'Fixed Amount',
    ]);

    $team = Team::factory()->create();

    expect(fn () => LateFeeConfiguration::create([
        'team_id' => $team->id,
        'fee_type' => 'percentage',
        'fee_amount' => 150,
        'grace_period_days' => 5,
        'frequency' => 'one-time',
    ]))->toThrow(InvalidArgumentException::class, 'Percentage fee cannot exceed 100%');

    expect(fn () => LateFeeConfiguration::create([
        'team_id' => $team->id,
        'fee_type' => 'fixed',
        'fee_amount' => -10,
        'grace_period_days' => 5,
        'frequency' => 'one-time',
    ]))->toThrow(InvalidArgumentException::class, 'Fee amount cannot be negative');

    expect(fn () => LateFeeConfiguration::create([
        'team_id' => $team->id,
        'fee_type' => 'fixed',
        'fee_amount' => 10,
        'grace_period_days' => -2,
        'frequency' => 'one-time',
    ]))->toThrow(InvalidArgumentException::class, 'Grace period days cannot be negative');
});

it('casts is_active to boolean on ReminderSetting', function (): void {
    $team = Team::factory()->create();

    $setting = ReminderSetting::create([
        'team_id' => $team->id,
        'days_before_reminder' => 3,
        'reminder_frequency' => 5,
        'max_reminders' => 3,
        'is_active' => 1,
    ]);

    expect($setting->fresh()->is_active)->toBeTrue();

    $setting->update(['is_active' => 0]);

    expect($setting->fresh()->is_active)->toBeFalse();
});

it('resolves relations and suspension state on ServiceSuspension', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $customer = Customer::factory()->create(['team_id' => $team->id]);
    $productService = Products_Service::factory()->create();

    $subscription = Subscription::create([
        'customer_id' => $customer->id,
        'product_service_id' => $productService->id,
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
        'status' => 'suspended',
        'renewal_period' => 'monthly',
    ]);

    $invoice = Invoice::factory()->create([
        'team_id' => $team->id,
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
    ]);

    $suspension = ServiceSuspension::create([
        'subscription_id' => $subscription->id,
        'invoice_id' => $invoice->id,
        'reason' => 'overdue_payment',
        'suspended_at' => now(),
        'suspended_by' => $user->id,
        'is_active' => true,
    ]);

    expect($suspension->subscription())->toBeInstanceOf(BelongsTo::class)
        ->and($suspension->invoice())->toBeInstanceOf(BelongsTo::class)
        ->and($suspension->suspendedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($suspension->unsuspendedBy())->toBeInstanceOf(BelongsTo::class)
        ->and($suspension->subscription->is($subscription))->toBeTrue()
        ->and($suspension->invoice->is($invoice))->toBeTrue()
        ->and($suspension->suspendedBy->is($user))->toBeTrue()
        ->and($suspension->isSuspended())->toBeTrue();

    $suspension->unsuspend($user->id);
    $fresh = $suspension->fresh();

    expect($fresh->isSuspended())->toBeFalse()
        ->and($fresh->unsuspendedBy->is($user))->toBeTrue();
});
