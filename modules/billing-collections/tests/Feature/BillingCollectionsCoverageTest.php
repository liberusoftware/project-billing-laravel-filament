<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ProcessInvoiceReminders;
use App\Console\Commands\SuspendOverdueServices;
use App\Http\Controllers\InvoiceDisputeController;
use App\Mail\UpcomingInvoiceReminder;
use App\Models\Customer;
use App\Models\DisputeMessage;
use App\Models\Invoice;
use App\Models\InvoiceDispute;
use App\Models\LateFeeConfiguration;
use App\Models\ReminderSetting;
use App\Models\ServiceSuspension;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CurrencyService;
use App\Services\DisputeService;
use App\Services\ServiceAutomationService;
use App\Services\ServiceProvisioningService;
use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class BillingCollectionsCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_invoice_reminders_command_paths(): void
    {
        $provisioning = Mockery::mock(ServiceProvisioningService::class);
        $currency = Mockery::mock(CurrencyService::class);
        $command = new ProcessInvoiceReminders($provisioning, $currency);
        $command->setLaravel($this->app);

        // Already running lock path
        Cache::put('processing_invoice_reminders', true, 60);
        $statusLocked = $command->run(new ArrayInput([]), new BufferedOutput());
        $this->assertSame(1, $statusLocked);
        Cache::forget('processing_invoice_reminders');

        // Success path with mocked BillingService via reflection
        $billingMock = Mockery::mock(BillingService::class);
        $billingMock->shouldReceive('sendUpcomingInvoiceReminders')->once()->andReturn(2);
        $billingMock->shouldReceive('sendOverdueReminders')->once()->andReturn(3);

        $ref = new \ReflectionProperty(ProcessInvoiceReminders::class, 'billingService');
        $ref->setValue($command, $billingMock);

        $statusOk = $command->run(new ArrayInput([]), new BufferedOutput());
        $this->assertSame(0, $statusOk);
        $this->assertNull(Cache::get('processing_invoice_reminders'));

        // Exception path
        $failingMock = Mockery::mock(BillingService::class);
        $failingMock->shouldReceive('sendUpcomingInvoiceReminders')->once()->andThrow(new Exception('Mail down'));
        $ref->setValue($command, $failingMock);

        $statusFail = $command->run(new ArrayInput([]), new BufferedOutput());
        $this->assertSame(1, $statusFail);
        $this->assertNull(Cache::get('processing_invoice_reminders'));
    }

    public function test_send_invoice_reminders_command_paths(): void
    {
        $billingMock = Mockery::mock(BillingService::class);
        $billingMock->shouldReceive('sendUpcomingDueReminders')->once();
        $billingMock->shouldReceive('sendOverdueReminders')->once()->andReturn(1);
        $this->app->instance(BillingService::class, $billingMock);

        $this->artisan('invoices:send-reminders')->assertExitCode(0);

        $failingMock = Mockery::mock(BillingService::class);
        $failingMock->shouldReceive('sendUpcomingDueReminders')->once()->andThrow(new Exception('SMS error'));
        $this->app->instance(BillingService::class, $failingMock);

        $this->artisan('invoices:send-reminders')->assertExitCode(1);
    }

    public function test_suspend_overdue_services_command_paths(): void
    {
        $automationMock = Mockery::mock(ServiceAutomationService::class);
        $automationMock->shouldReceive('suspendOverdueServices')->with(10)->once()->andReturn(4);

        $command = new SuspendOverdueServices($automationMock);
        $command->setLaravel($this->app);

        Cache::put('suspending_overdue_services', true, 60);
        $this->assertSame(1, $command->run(new ArrayInput(['--days' => '10']), new BufferedOutput()));
        Cache::forget('suspending_overdue_services');

        $this->assertSame(0, $command->run(new ArrayInput(['--days' => '10']), new BufferedOutput()));
        $this->assertNull(Cache::get('suspending_overdue_services'));

        $failingMock = Mockery::mock(ServiceAutomationService::class);
        $failingMock->shouldReceive('suspendOverdueServices')->once()->andThrow(new Exception('Suspend error'));

        $failingCommand = new SuspendOverdueServices($failingMock);
        $failingCommand->setLaravel($this->app);

        $this->assertSame(1, $failingCommand->run(new ArrayInput([]), new BufferedOutput()));
        $this->assertNull(Cache::get('suspending_overdue_services'));
    }

    public function test_invoice_dispute_controller_endpoints(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
        $invoice = Invoice::factory()->create([
            'team_id' => $user->currentTeam->id,
            'customer_id' => $customer->id,
        ]);

        Route::middleware(SubstituteBindings::class)->group(function (): void {
            Route::post('/test/invoices/{invoice}/disputes', [InvoiceDisputeController::class, 'store']);
            Route::patch('/test/disputes/{dispute}', [InvoiceDisputeController::class, 'update']);
            Route::post('/test/disputes/{dispute}/messages', [InvoiceDisputeController::class, 'addMessage']);
        });

        $disputeService = Mockery::mock(DisputeService::class);
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

        $disputeService->shouldReceive('createDispute')->once()->andReturn($dispute);
        $disputeService->shouldReceive('updateDisputeStatus')->once()->andReturn($dispute);
        $disputeService->shouldReceive('addMessage')->once()->andReturn($message);
        $this->app->instance(DisputeService::class, $disputeService);

        $this->postJson("/test/invoices/{$invoice->id}/disputes", [
            'reason' => 'Duplicate charge',
            'description' => 'Charged twice for the same cycle',
        ])->assertCreated();

        $this->patchJson("/test/disputes/{$dispute->id}", [
            'status' => 'resolved',
            'resolution_notes' => 'Refunded duplicate charge',
        ])->assertOk();

        $this->postJson("/test/disputes/{$dispute->id}/messages", [
            'message' => 'Here is the receipt',
            'attachments' => ['receipt.pdf'],
        ])->assertCreated();
    }

    public function test_upcoming_invoice_reminder_mailable_build(): void
    {
        $template = (object) [
            'subject' => 'Invoice {{invoice_number}} due soon',
            'body' => 'Hello {{customer_name}}, your invoice is due.',
        ];
        $mailable = new UpcomingInvoiceReminder([
            'invoice_number' => 'INV-100',
            'customer_name' => 'Jane Doe',
        ], $template);

        $built = $mailable->build();

        $this->assertSame('Invoice INV-100 due soon', $built->subject);
        $this->assertSame('emails.upcoming-invoice-reminder', $built->view);
        $this->assertSame('Hello Jane Doe, your invoice is due.', $built->viewData['content']);
    }

    public function test_collections_models_relations_and_validation(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id]);

        $dispute = InvoiceDispute::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'status' => 'resolved',
            'reason' => 'Billing error',
            'description' => 'Wrong amount',
            'resolved_by' => $user->id,
        ]);
        $this->assertInstanceOf(BelongsTo::class, $dispute->resolver());
        $this->assertEquals($user->id, $dispute->resolver->id);

        $msg = DisputeMessage::create([
            'invoice_dispute_id' => $dispute->id,
            'user_id' => $user->id,
            'message' => 'Resolved',
        ]);
        $this->assertInstanceOf(BelongsTo::class, $msg->invoiceDispute());
        $this->assertInstanceOf(BelongsTo::class, $msg->user());
        $this->assertEquals($dispute->id, $msg->invoiceDispute->id);
        $this->assertEquals($user->id, $msg->user->id);

        // LateFeeConfiguration options & validation
        $this->assertArrayHasKey('monthly', LateFeeConfiguration::getFrequencyOptions());
        $this->assertArrayHasKey('percentage', LateFeeConfiguration::getFeeTypeOptions());

        try {
            LateFeeConfiguration::create([
                'team_id' => $team->id,
                'fee_type' => 'percentage',
                'fee_amount' => 150,
                'grace_period_days' => 5,
                'frequency' => 'one-time',
            ]);
            $this->fail('Expected percentage > 100 exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('100%', $e->getMessage());
        }

        try {
            LateFeeConfiguration::create([
                'team_id' => $team->id,
                'fee_type' => 'fixed',
                'fee_amount' => -5,
                'grace_period_days' => 5,
                'frequency' => 'one-time',
            ]);
            $this->fail('Expected negative fee exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('negative', $e->getMessage());
        }

        try {
            LateFeeConfiguration::create([
                'team_id' => $team->id,
                'fee_type' => 'fixed',
                'fee_amount' => 10,
                'grace_period_days' => -1,
                'frequency' => 'one-time',
            ]);
            $this->fail('Expected negative grace period exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Grace period', $e->getMessage());
        }

        // ReminderSetting
        $reminderSetting = ReminderSetting::create([
            'team_id' => $team->id,
            'days_before_reminder' => 3,
            'reminder_frequency' => 2,
            'max_reminders' => 3,
            'is_active' => true,
        ]);
        $this->assertTrue($reminderSetting->is_active);

        // ServiceSuspension
        $subscription = Subscription::factory()->create(['team_id' => $team->id]);
        $suspension = ServiceSuspension::create([
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'reason' => 'overdue_payment',
            'suspended_at' => now(),
            'suspended_by' => $user->id,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(BelongsTo::class, $suspension->subscription());
        $this->assertInstanceOf(BelongsTo::class, $suspension->invoice());
        $this->assertInstanceOf(BelongsTo::class, $suspension->suspendedBy());
        $this->assertInstanceOf(BelongsTo::class, $suspension->unsuspendedBy());
        $this->assertTrue($suspension->isSuspended());

        $suspension->unsuspend($user->id);
        $this->assertFalse($suspension->fresh()->isSuspended());
    }
}
