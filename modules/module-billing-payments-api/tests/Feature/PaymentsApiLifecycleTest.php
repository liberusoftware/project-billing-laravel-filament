<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Liberu\Billing\Invoicing\Actions\AddInvoiceLine;
use Liberu\Billing\Invoicing\Actions\CreateInvoice;
use Liberu\Billing\Invoicing\Actions\FinalizeInvoice;
use Liberu\Billing\Payments\Contracts\GatewayDriver;
use Liberu\Billing\Payments\Models\Payment;
use Liberu\Billing\Payments\Services\GatewayManager;

uses(RefreshDatabase::class);

it('runs full payment, method, and mandate lifecycles through the published payments API', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $user->switchTeam($user->ownedTeams()->sole());
    Sanctum::actingAs($user, ['billing.payments.read', 'billing.payments.write']);

    app(GatewayManager::class)->register('api-test', new class() implements GatewayDriver
    {
        public function capture(Payment $payment): array
        {
            return ['reference' => 'api-cap-1'];
        }

        public function refund(Payment $payment, int $amountMinor): array
        {
            return ['reference' => 'api-ref-1'];
        }
    });

    $invoice = app(CreateInvoice::class)->execute(['team_id' => $user->current_team_id, 'currency' => 'USD']);
    app(AddInvoiceLine::class)->execute($invoice, 'API Service', 1, 1000);
    $invoice = app(FinalizeInvoice::class)->execute($invoice->refresh());

    $paymentId = $this->postJson(route('billing.payments.store'), [
        'amount_minor' => 1000,
        'currency' => 'usd',
        'payment_method' => 'card',
        'gateway' => 'api-test',
    ])->assertCreated()
        ->assertJsonPath('data.attributes.amount_minor', 1000)
        ->assertJsonPath('data.attributes.currency', 'USD')
        ->json('data.id');

    $this->getJson(route('billing.payments.index', ['per_page' => 10]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $paymentId);

    $this->getJson(route('billing.payments.show', $paymentId))
        ->assertOk()
        ->assertJsonPath('data.id', $paymentId);

    $this->postJson(route('billing.payments.capture', $paymentId))
        ->assertOk()
        ->assertJsonPath('data.attributes.status', 'captured')
        ->assertJsonPath('data.attributes.provider_reference', 'api-cap-1');

    $this->postJson(route('billing.payments.allocate', $paymentId), [
        'amount_minor' => 600,
        'invoice_id' => $invoice->getKey(),
    ])->assertCreated()
        ->assertJsonPath('data.attributes.amount_minor', 600)
        ->assertJsonPath('data.attributes.invoice_id', $invoice->getKey());

    $this->postJson(route('billing.payments.refund', $paymentId), [
        'amount_minor' => 400,
        'reason' => 'partial refund',
    ])->assertOk()
        ->assertJsonPath('data.attributes.refunded_minor', 400);

    $this->postJson(route('billing.payments.dispute', $paymentId), [
        'amount_minor' => 200,
        'reason' => 'customer inquiry',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'disputed');

    $this->postJson(route('billing.payments.reconcile', $paymentId), [
        'provider_reference' => 'api-cap-1',
        'matched' => true,
        'notes' => 'Matched in settlement',
    ])->assertCreated()
        ->assertJsonPath('data.attributes.status', 'matched')
        ->assertJsonPath('data.attributes.notes', 'Matched in settlement');

    $methodId = $this->postJson(route('billing.payments.methods.store'), [
        'type' => 'card',
        'provider' => 'stripe',
        'display_name' => 'Visa 4242',
        'last_four' => '4242',
        'is_default' => true,
    ])->assertCreated()
        ->assertJsonPath('data.attributes.last_four', '4242')
        ->json('data.id');

    $this->getJson(route('billing.payments.methods.index', ['per_page' => 5]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $methodId);

    $this->patchJson(route('billing.payments.methods.status', $methodId), [
        'status' => 'inactive',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'inactive');

    $mandateId = $this->postJson(route('billing.payments.mandates.store'), [
        'payment_method_id' => (int) $methodId,
        'provider' => 'stripe',
        'provider_reference' => 'mandate-1',
    ])->assertCreated()
        ->assertJsonPath('data.attributes.status', 'pending')
        ->json('data.id');

    $this->getJson(route('billing.payments.mandates.index', ['page' => ['size' => 5]]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $mandateId);

    $this->patchJson(route('billing.payments.mandates.status', $mandateId), [
        'status' => 'active',
    ])->assertOk()
        ->assertJsonPath('data.attributes.status', 'active');
});
