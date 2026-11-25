<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\PaymentGateways\Pages\CreatePaymentGateway;
use App\Filament\Admin\Resources\PaymentGateways\Pages\EditPaymentGateway;
use App\Filament\Admin\Resources\PaymentGateways\Pages\ListPaymentGateways;
use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Filament\App\Resources\PartialPayments\Pages\CreatePartialPayment;
use App\Filament\App\Resources\PartialPayments\Pages\EditPartialPayment;
use App\Filament\App\Resources\PartialPayments\Pages\ListPartialPayments;
use App\Filament\App\Resources\Refunds\Pages\CreateRefund;
use App\Filament\App\Resources\Refunds\Pages\EditRefund;
use App\Filament\App\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Components\PaymentForm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\RefundService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('lists, creates, edits, and deletes payment gateways in the admin panel', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($user->currentTeam);

    $gateway = PaymentGateway::query()->create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Stripe Main',
        'api_key' => 'pk_test_123',
        'secret_key' => 'sk_test_123',
        'is_active' => true,
    ]);

    Livewire::test(ListPaymentGateways::class)
        ->assertSuccessful()
        ->assertActionExists('create')
        ->assertCanSeeTableRecords([$gateway]);

    Livewire::test(CreatePaymentGateway::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Paddle Billing',
            'api_key' => 'pdl_pk_123',
            'secret_key' => 'pdl_sk_123',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = PaymentGateway::query()->where('name', 'Paddle Billing')->firstOrFail();

    Livewire::test(EditPaymentGateway::class, ['record' => $created->getRouteKey()])
        ->assertSuccessful()
        ->assertActionExists('delete')
        ->fillForm([
            'name' => 'Paddle Updated',
            'api_key' => 'pdl_pk_456',
            'secret_key' => 'pdl_sk_456',
            'is_active' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($created->fresh()->name)->toBe('Paddle Updated')
        ->and($created->fresh()->is_active)->toBeFalse();

    Livewire::test(EditPaymentGateway::class, ['record' => $created->getRouteKey()])
        ->callAction(DeleteAction::class);

    $this->assertDatabaseMissing('payment_gateways', ['id' => $created->id]);
});

it('configures payment plan resource form schema and table', function (): void {
    $schema = PaymentPlanResource::form(Schema::make());
    $table = PaymentPlanResource::table(Table::make(new ListPaymentGateways()));

    expect($schema->getComponents())->toHaveCount(4)
        ->and($table->getColumns())->toHaveCount(5)
        ->and($table->getRecordActions())->toHaveCount(2);
});

it('renders partial payments list, create, and edit pages in the app panel', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($user->currentTeam->id);

    foreach ([
        'view_any_partial::payment',
        'view_partial::payment',
        'create_partial::payment',
        'update_partial::payment',
        'delete_partial::payment',
        'delete_any_partial::payment',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $this->actingAs($user);
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($user->currentTeam);

    PaymentGateway::query()->create([
        'team_id' => $user->currentTeam->id,
        'name' => 'Active GW',
        'api_key' => 'pk_act',
        'secret_key' => 'sk_act',
        'is_active' => true,
    ]);

    $pendingInvoice = Invoice::factory()->create([
        'team_id' => $user->currentTeam->id,
        'status' => 'pending',
        'total_amount' => 100.00,
    ]);
    $partialInvoice = Invoice::factory()->create([
        'team_id' => $user->currentTeam->id,
        'status' => 'partially_paid',
        'total_amount' => 200.00,
    ]);
    $paidInvoice = Invoice::factory()->create([
        'team_id' => $user->currentTeam->id,
        'status' => 'paid',
        'total_amount' => 300.00,
    ]);

    Livewire::test(ListPartialPayments::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$pendingInvoice, $partialInvoice])
        ->assertCanNotSeeTableRecords([$paidInvoice]);

    Livewire::test(CreatePartialPayment::class)
        ->assertSuccessful();

    Livewire::test(EditPartialPayment::class, ['record' => $pendingInvoice->getRouteKey()])
        ->assertSuccessful();

    $listActionsMethod = new ReflectionMethod(ListPartialPayments::class, 'getActions');
    $editActionsMethod = new ReflectionMethod(EditPartialPayment::class, 'getActions');

    expect($listActionsMethod->invoke(new ListPartialPayments()))->toHaveCount(1)
        ->and($editActionsMethod->invoke(new EditPartialPayment()))->toHaveCount(1);
});

it('renders refunds list, filters, table actions, forms, and page redirect helpers in the app panel', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($user->currentTeam->id);

    foreach ([
        'view_any_refund',
        'view_refund',
        'create_refund',
        'update_refund',
        'delete_refund',
        'delete_any_refund',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $this->actingAs($user);
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($user->currentTeam);

    $invoice = Invoice::factory()->create([
        'team_id' => $user->currentTeam->id,
        'total_amount' => 100.00,
        'currency' => 'USD',
    ]);

    $pNone = Payment::factory()->create(['team_id' => $user->currentTeam->id, 'invoice_id' => $invoice->id, 'amount' => 100.00, 'currency' => 'USD']);
    $pNone->forceFill(['refund_status' => 'none', 'refunded_amount' => 0.00])->save();

    $pPartial = Payment::factory()->create(['team_id' => $user->currentTeam->id, 'invoice_id' => $invoice->id, 'amount' => 100.00, 'currency' => 'USD']);
    $pPartial->forceFill(['refund_status' => 'partial', 'refunded_amount' => 25.00])->save();

    $pFull = Payment::factory()->create(['team_id' => $user->currentTeam->id, 'invoice_id' => $invoice->id, 'amount' => 100.00, 'currency' => 'USD']);
    $pFull->forceFill(['refund_status' => 'full', 'refunded_amount' => 100.00])->save();

    $pCompleted = Payment::factory()->create(['team_id' => $user->currentTeam->id, 'invoice_id' => $invoice->id, 'amount' => 100.00, 'currency' => 'USD']);
    $pCompleted->forceFill(['refund_status' => 'completed', 'refunded_amount' => 100.00])->save();

    $this->mock(RefundService::class, function ($mock): void {
        $mock->shouldReceive('processRefund')
            ->once()
            ->andReturn(['success' => true, 'message' => 'Refund processed successfully.']);
        $mock->shouldReceive('processRefund')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Gateway declined']);
        $mock->shouldReceive('processRefund')
            ->once()
            ->andThrow(new Exception('Unexpected error'));
    });

    Livewire::test(ListRefunds::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$pNone, $pPartial, $pFull, $pCompleted])
        ->filterTable('refund_status', 'partial')
        ->assertCanSeeTableRecords([$pPartial])
        ->assertCanNotSeeTableRecords([$pNone, $pFull])
        ->resetTableFilters()
        // Validation error when amount exceeds remaining refundable amount
        ->callTableAction('refund', $pNone, data: ['amount' => 250.00, 'reason' => 'Too large'])
        ->assertHasTableActionErrors(['amount'])
        // Success notification branch
        ->callTableAction('refund', $pNone, data: ['amount' => 20.00, 'reason' => 'Customer request'])
        ->assertNotified('Refund processed successfully')
        // Failure notification branch
        ->callTableAction('refund', $pNone, data: ['amount' => 20.00, 'reason' => 'Retry'])
        ->assertNotified('Refund failed')
        // Exception notification branch
        ->callTableAction('refund', $pNone, data: ['amount' => 20.00, 'reason' => 'Error path'])
        ->assertNotified('Error processing refund');

    // CreateRefund form: afterStateUpdated + custom validation rule
    request()->merge(['data' => ['payment_id' => $pNone->id]]);
    Livewire::test(CreateRefund::class)
        ->assertSuccessful()
        ->fillForm([
            'payment_id' => $pNone->id,
            'amount' => 500.00,
            'reason' => 'Exceeds limit',
        ])
        ->call('create')
        ->assertHasFormErrors(['amount']);

    // EditRefund page renders hint with record
    Livewire::test(EditRefund::class, ['record' => $pPartial->getRouteKey()])
        ->assertSuccessful();

    $createRedirect = new ReflectionMethod(CreateRefund::class, 'getRedirectUrl');
    $editRedirect = new ReflectionMethod(EditRefund::class, 'getRedirectUrl');
    $listActions = new ReflectionMethod(ListRefunds::class, 'getActions');
    $editActions = new ReflectionMethod(EditRefund::class, 'getActions');

    expect($createRedirect->invoke(new CreateRefund()))->toBeString()
        ->and($editRedirect->invoke(new EditRefund()))->toBeString()
        ->and($listActions->invoke(new ListRefunds()))->toHaveCount(1)
        ->and($editActions->invoke(new EditRefund()))->toHaveCount(1);
});

it('builds the payment form component and toggles card fields based on payment method', function (): void {
    $paypalSchema = Schema::make()
        ->state(['payment_method' => 'paypal'])
        ->components([PaymentForm::make()]);

    $paypalSection = $paypalSchema->getComponents(withHidden: true)[0];
    $paypalFields = $paypalSection->getChildSchema()->getComponents(withHidden: true);

    expect($paypalFields)->toHaveCount(3)
        ->and($paypalFields[1]->isHidden())->toBeTrue()
        ->and($paypalFields[2]->isHidden())->toBeTrue();

    $stripeSchema = Schema::make()
        ->state(['payment_method' => 'stripe'])
        ->components([PaymentForm::make()]);

    $stripeSection = $stripeSchema->getComponents(withHidden: true)[0];
    $stripeFields = $stripeSection->getChildSchema()->getComponents(withHidden: true);

    expect($stripeFields[1]->isHidden())->toBeFalse()
        ->and($stripeFields[2]->isHidden())->toBeFalse();
});
