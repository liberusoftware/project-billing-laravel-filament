<?php

declare(strict_types=1);

use App\Mail\InvoiceGenerated;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Invoice_Item;
use App\Models\InvoiceTemplate;
use App\Models\PaymentGateway;
use App\Models\Products_Service;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Policies\InvoiceTemplatePolicy;
use App\Services\InvoiceCheckoutService;
use App\Services\PaymentGatewayService;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

it('covers Credit model customer relation and expiry_date cast', function (): void {
    $customer = Customer::factory()->create();

    $credit = Credit::query()->create([
        'customer_id' => $customer->id,
        'amount' => '75.50',
        'description' => 'Service credit',
        'expiry_date' => '2026-12-31',
    ]);

    $fresh = $credit->fresh();

    expect($fresh->customer->is($customer))->toBeTrue()
        ->and($fresh->expiry_date)->toBeInstanceOf(Carbon::class)
        ->and($fresh->expiry_date->toDateString())->toBe('2026-12-31');
});

it('covers Invoice_Item currency, invoice, and product relations', function (): void {
    Currency::query()->firstOrCreate(
        ['code' => 'USD'],
        [
            'name' => 'US Dollar',
            'symbol' => '$',
            'exchange_rate' => 1.0,
            'is_enabled' => true,
            'is_base' => true,
            'decimal_precision' => 2,
        ]
    );

    $invoice = Invoice::factory()->create(['currency' => 'USD']);
    $product = Products_Service::factory()->create();

    $item = Invoice_Item::query()->create([
        'invoice_id' => $invoice->id,
        'product_service_id' => $product->id,
        'description' => 'Managed Hosting',
        'quantity' => 2,
        'unit_price' => 50.00,
        'total_price' => 100.00,
        'currency' => 'USD',
    ]);

    expect($item->currency()->first()?->code)->toBe('USD')
        ->and($item->invoice->is($invoice))->toBeTrue()
        ->and($item->product->is($product))->toBeTrue();
});

it('covers InvoiceTemplate casts, invoices relation, getDefault, logoUrl, and styledHtml', function (): void {
    Storage::fake('public');

    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->id;
    $user->forceFill(['current_team_id' => $teamId])->save();
    $this->actingAs($user);

    $template = InvoiceTemplate::query()->create([
        'name' => 'Default Branded',
        'company_name' => 'Acme Billing Ltd',
        'company_address' => '123 Main St',
        'company_phone' => '+15550001111',
        'company_email' => 'billing@acme.test',
        'logo_path' => 'logos/acme.png',
        'header_text' => 'Thank you for your business',
        'footer_text' => 'Net 30',
        'color_scheme' => '#123456',
        'is_default' => true,
        'team_id' => $teamId,
    ]);

    $noLogoTemplate = InvoiceTemplate::query()->create([
        'name' => 'Plain Template',
        'company_name' => 'Plain Co',
        'color_scheme' => '#000000',
        'is_default' => false,
        'team_id' => $teamId,
    ]);

    $invoice = Invoice::factory()->create([
        'team_id' => $teamId,
        'invoice_template_id' => $template->id,
    ]);

    expect($template->is_default)->toBeTrue()
        ->and($template->invoices->pluck('id')->all())->toContain($invoice->id)
        ->and(InvoiceTemplate::getDefault()?->is($template))->toBeTrue()
        ->and($template->logo_url)->toContain('logos/acme.png')
        ->and($noLogoTemplate->logo_url)->toBeNull()
        ->and($template->styled_html)->toContain('#123456')
        ->and($template->styled_html)->toContain('<style>');
});

it('covers all InvoicePolicy authorization methods for denied and permitted users', function (): void {
    $policy = new InvoicePolicy();
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create();

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->view($user, $invoice))->toBeFalse()
        ->and($policy->create($user))->toBeFalse()
        ->and($policy->update($user, $invoice))->toBeFalse()
        ->and($policy->delete($user, $invoice))->toBeFalse()
        ->and($policy->deleteAny($user))->toBeFalse()
        ->and($policy->forceDelete($user, $invoice))->toBeFalse()
        ->and($policy->forceDeleteAny($user))->toBeFalse()
        ->and($policy->restore($user, $invoice))->toBeFalse()
        ->and($policy->restoreAny($user))->toBeFalse()
        ->and($policy->replicate($user, $invoice))->toBeFalse()
        ->and($policy->reorder($user))->toBeFalse();

    $permissions = [
        'view_any_partial::payment',
        'view_partial::payment',
        'create_partial::payment',
        'update_partial::payment',
        'delete_partial::payment',
        'delete_any_partial::payment',
        'force_delete_partial::payment',
        'force_delete_any_partial::payment',
        'restore_partial::payment',
        'restore_any_partial::payment',
        'replicate_partial::payment',
        'reorder_partial::payment',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $invoice))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->update($user, $invoice))->toBeTrue()
        ->and($policy->delete($user, $invoice))->toBeTrue()
        ->and($policy->deleteAny($user))->toBeTrue()
        ->and($policy->forceDelete($user, $invoice))->toBeTrue()
        ->and($policy->forceDeleteAny($user))->toBeTrue()
        ->and($policy->restore($user, $invoice))->toBeTrue()
        ->and($policy->restoreAny($user))->toBeTrue()
        ->and($policy->replicate($user, $invoice))->toBeTrue()
        ->and($policy->reorder($user))->toBeTrue();
});

it('covers all InvoiceTemplatePolicy methods for same-team and cross-team users', function (): void {
    $policy = new InvoiceTemplatePolicy();
    $user = User::factory()->withPersonalTeam()->create();
    $otherUser = User::factory()->withPersonalTeam()->create();

    $teamId = $user->currentTeam->id;
    $user->forceFill(['current_team_id' => $teamId])->save();
    $otherUser->forceFill(['current_team_id' => $otherUser->currentTeam->id])->save();

    $template = InvoiceTemplate::query()->create([
        'name' => 'Team Template',
        'company_name' => 'Team Co',
        'color_scheme' => '#111111',
        'is_default' => false,
        'team_id' => $teamId,
    ]);

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->view($user, $template))->toBeTrue()
        ->and($policy->update($user, $template))->toBeTrue()
        ->and($policy->delete($user, $template))->toBeTrue()
        ->and($policy->view($otherUser, $template))->toBeFalse()
        ->and($policy->update($otherUser, $template))->toBeFalse()
        ->and($policy->delete($otherUser, $template))->toBeFalse();
});

it('covers InvoiceGenerated mailable with fallback view and custom EmailTemplate', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->id;
    $customer = Customer::factory()->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.test',
        'team_id' => $teamId,
    ]);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'team_id' => $teamId,
        'invoice_number' => 'INV-900001',
        'total_amount' => 149.95,
        'currency' => 'USD',
        'due_date' => Carbon::parse('2026-08-15'),
    ]);

    // Without EmailTemplate (fallback)
    view()->replaceNamespace('mail', app(Markdown::class)->htmlComponentPaths());
    $fallbackMail = new InvoiceGenerated($invoice);
    $fallbackEnvelope = $fallbackMail->envelope();
    $fallbackContent = $fallbackMail->content();

    expect($fallbackEnvelope->subject)->toBe('Invoice Generated')
        ->and($fallbackContent->view)->toBe('emails.dynamic-template')
        ->and($fallbackContent->with['invoice']->is($invoice))->toBeTrue()
        ->and($fallbackContent->with['content'])->toContain('INV-900001')
        ->and($fallbackMail->attachments())->toBe([]);

    // With custom EmailTemplate
    EmailTemplate::query()->create([
        'name' => 'Custom Invoice Generated',
        'type' => 'invoice_generated',
        'subject' => 'Invoice {{invoice_number}} for {{customer_name}}',
        'body' => 'Total {{amount}} due on {{due_date}} for {{customer_name}} ({{invoice_number}}).',
        'team_id' => $teamId,
        'is_default' => false,
    ]);

    $templatedMail = new InvoiceGenerated($invoice);
    $templatedEnvelope = $templatedMail->envelope();
    $templatedContent = $templatedMail->content();

    expect($templatedEnvelope->subject)->toBe('Invoice INV-900001 for Jane Doe')
        ->and($templatedContent->view)->toBe('emails.dynamic-template')
        ->and($templatedContent->with['content'])
        ->toContain('149.95')
        ->toContain('2026-08-15')
        ->toContain('Jane Doe')
        ->toContain('INV-900001')
        ->and($templatedMail->attachments())->toBe([]);
});

it('covers all InvoiceCheckoutService error branches', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->id;
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;

    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'team_id' => $teamId,
    ]);
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Pro',
        'code' => 'pro-checkout',
        'price' => 40,
        'currency' => 'USD',
    ]);
    $subscription = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'subscription_plan_id' => $plan->id,
        'renewal_period' => 'monthly',
    ]);

    $service = app(InvoiceCheckoutService::class);

    // 1. Non-payable invoice status (paid)
    $paidInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
        'team_id' => $teamId,
        'status' => 'paid',
        'total_amount' => 40,
        'currency' => 'USD',
    ]);

    expect(fn () => $service->paddleCheckoutUrl($paidInvoice, $user))
        ->toThrow(RuntimeException::class, 'This invoice cannot be paid through checkout.');

    // 2. Missing/mismatched Paddle catalog price
    $pendingInvoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
        'team_id' => $teamId,
        'status' => 'pending',
        'total_amount' => 40,
        'currency' => 'USD',
    ]);
    config()->set('services.paddle.invoice_prices', []);

    expect(fn () => $service->paddleCheckoutUrl($pendingInvoice, $user))
        ->toThrow(RuntimeException::class, 'No matching Paddle catalog price is configured.');

    // 3. Gateway belongs to another team
    config()->set('services.paddle.invoice_prices', [
        'pro-checkout' => [
            'monthly' => [
                'price_id' => 'pri_pro',
                'amount' => '40.00',
                'currency' => 'USD',
            ],
        ],
    ]);
    $otherTeamGateway = PaymentGateway::query()->create([
        'name' => 'Paddle',
        'is_active' => true,
        'api_key' => 'k',
        'secret_key' => 's',
        'team_id' => $otherTeam->id,
    ]);
    config()->set('services.paddle.webhook_gateway_id', $otherTeamGateway->id);

    expect(fn () => $service->paddleCheckoutUrl($pendingInvoice, $user))
        ->toThrow(RuntimeException::class, 'The payment gateway belongs to another team.');

    // 4. Provider returns insecure/invalid checkout URL
    $sameTeamGateway = PaymentGateway::query()->create([
        'name' => 'Paddle',
        'is_active' => true,
        'api_key' => 'k2',
        'secret_key' => 's2',
        'team_id' => $teamId,
    ]);
    config()->set('services.paddle.webhook_gateway_id', $sameTeamGateway->id);

    $this->mock(PaymentGatewayService::class)
        ->shouldReceive('processPayment')
        ->once()
        ->andReturn(['checkout' => ['url' => 'http://insecure.paddle.test/checkout']]);

    expect(fn () => $service->paddleCheckoutUrl($pendingInvoice, $user))
        ->toThrow(RuntimeException::class, 'Paddle did not return a secure checkout URL.');
});
