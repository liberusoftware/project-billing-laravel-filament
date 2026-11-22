<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderFormTemplate;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * N4 — order-form templates pipeline. A template offers a set of plans; placing
 * an order drives create-subscription -> invoice -> order, scoped to offered plans.
 */
class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function plan(float $price = 19.99): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Starter',
            'code' => 'starter-'.fake()->unique()->numberBetween(1, 99999),
            'price' => $price,
            'currency' => 'USD',
            'is_active' => true,
            'trial_days' => 0,
        ]);
    }

    public function test_place_order_creates_subscription_invoice_and_order_all_linked(): void
    {
        $customer = Customer::factory()->create();
        $plan = $this->plan(19.99);
        $template = OrderFormTemplate::factory()->offering([$plan->id])->create();

        $order = app(OrderService::class)->placeOrder($template, $customer, [
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ]);

        $this->assertSame('completed', $order->status);
        $this->assertSame($template->id, $order->order_form_template_id);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertNotNull($order->subscription_id);
        $this->assertNotNull($order->invoice_id);

        $subscription = Subscription::find($order->subscription_id);
        $this->assertSame($plan->id, $subscription->subscription_plan_id);

        $invoice = Invoice::find($order->invoice_id);
        $this->assertEquals(19.99, (float) $invoice->total_amount);
        $this->assertSame('pending', $invoice->status);
    }

    public function test_place_order_rejects_plan_not_offered_by_template(): void
    {
        $customer = Customer::factory()->create();
        $offered = $this->plan();
        $other = $this->plan();
        $template = OrderFormTemplate::factory()->offering([$offered->id])->create();

        try {
            app(OrderService::class)->placeOrder($template, $customer, [
                'subscription_plan_id' => $other->id,
            ]);
            $this->fail('Expected InvalidArgumentException for an unoffered plan.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not offered', $e->getMessage());
        }

        // Nothing persisted on rejection.
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_place_order_rejects_inactive_plan_even_when_offered(): void
    {
        $customer = Customer::factory()->create();
        $plan = $this->plan();
        $plan->update(['is_active' => false]);
        $template = OrderFormTemplate::factory()->offering([$plan->id])->create();

        try {
            app(OrderService::class)->placeOrder($template, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
            ]);
            $this->fail('Expected InvalidArgumentException for an inactive plan.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not available', $e->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_template_offered_plans_round_trip(): void
    {
        $template = OrderFormTemplate::factory()->offering([3, 7])->create();

        $this->assertSame([3, 7], $template->fresh()->offeredPlanIds());
        $this->assertTrue($template->offersPlan(7));
        $this->assertFalse($template->offersPlan(99));
    }

    public function test_place_order_checkout_key_idempotency_and_validation_branches(): void
    {
        $customer = Customer::factory()->create();
        $plan = $this->plan(25.00);
        $template = OrderFormTemplate::factory()->offering([$plan->id])->create();
        $service = app(OrderService::class);

        // Invalid checkout key
        try {
            $service->placeOrder($template, $customer, [
                'subscription_plan_id' => $plan->id,
                'checkout_key' => 'not-a-uuid',
            ]);
            $this->fail('Expected InvalidArgumentException for invalid checkout key.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('valid checkout key', $e->getMessage());
        }

        // Valid checkout key + custom period days + matching expected_quote
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $first = $service->placeOrder($template, $customer, [
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => 'custom',
            'custom_period_days' => 45,
            'checkout_key' => $uuid,
            'expected_quote' => ['total' => '25.00', 'currency' => 'USD'],
        ]);

        // Idempotent replay with same checkout key & same selections returns existing order
        $replay = $service->placeOrder($template, $customer, [
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => 'custom',
            'custom_period_days' => 45,
            'checkout_key' => strtoupper($uuid),
        ]);
        $this->assertSame($first->id, $replay->id);

        // Different selections with same checkout key rejected
        try {
            $service->placeOrder($template, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'checkout_key' => $uuid,
            ]);
            $this->fail('Expected InvalidArgumentException for conflicting checkout key.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('different selections', $e->getMessage());
        }

        // Invalid billing cycle when expected_quote is provided
        try {
            $service->placeOrder($template, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'bogus_cycle',
                'expected_quote' => ['total' => '25.00', 'currency' => 'USD'],
            ]);
            $this->fail('Expected InvalidArgumentException for invalid billing cycle.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('valid billing cycle', $e->getMessage());
        }

        // Mismatched expected_quote
        try {
            $service->placeOrder($template, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'expected_quote' => ['total' => '99.99', 'currency' => 'USD'],
            ]);
            $this->fail('Expected InvalidArgumentException for stale quote.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('price has changed', $e->getMessage());
        }

        // Inactive template
        $template->update(['is_active' => false]);
        try {
            $service->placeOrder($template, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
            ]);
            $this->fail('Expected InvalidArgumentException for inactive template.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }
    }

    public function test_place_order_hosting_package_validation_and_success(): void
    {
        $customer = Customer::factory()->create();
        $plan = $this->plan(15.00);
        $nonHosting = \App\Models\Products_Service::factory()->create(['type' => 'domain']);
        $hosting = \App\Models\Products_Service::factory()->create(['type' => 'hosting']);
        $service = app(OrderService::class);

        $badProductTemplate = OrderFormTemplate::factory()->create([
            'is_active' => true,
            'config' => [
                'plan_ids' => [$plan->id],
                'hosting_packages' => [
                    ['plan_id' => $plan->id, 'product_service_id' => $nonHosting->id],
                ],
            ],
        ]);

        // Unsupported hosting billing cycle
        try {
            $service->placeOrder($badProductTemplate, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'custom',
                'domain' => 'example.com',
            ]);
            $this->fail('Expected InvalidArgumentException for unsupported hosting billing cycle.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('supported hosting billing cycle', $e->getMessage());
        }

        // Product not hosting type
        try {
            $service->placeOrder($badProductTemplate, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'domain' => 'example.com',
            ]);
            $this->fail('Expected InvalidArgumentException for non-hosting product.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('hosting package is not available', $e->getMessage());
        }

        $hostingTemplate = OrderFormTemplate::factory()->create([
            'is_active' => true,
            'config' => [
                'plan_ids' => [$plan->id],
                'hosting_packages' => [
                    ['plan_id' => $plan->id, 'product_service_id' => $hosting->id],
                ],
            ],
        ]);

        // Non-existing domain action
        try {
            $service->placeOrder($hostingTemplate, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'domain' => 'example.com',
                'domain_action' => 'register',
            ]);
            $this->fail('Expected InvalidArgumentException for non-existing domain action.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('requires an existing domain', $e->getMessage());
        }

        // Invalid domain format
        try {
            $service->placeOrder($hostingTemplate, $customer, [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'domain' => 'https://example.com/path',
                'domain_action' => 'existing',
            ]);
            $this->fail('Expected InvalidArgumentException for invalid domain.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('valid domain name', $e->getMessage());
        }

        // Valid hosting order
        $order = $service->placeOrder($hostingTemplate, $customer, [
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'domain' => 'My-Site.Example.COM.',
            'domain_action' => 'existing',
        ]);

        $this->assertSame('pending', $order->status);
        $subscription = Subscription::findOrFail($order->subscription_id);
        $this->assertSame($hosting->id, $subscription->product_service_id);
        $this->assertSame('my-site.example.com', $subscription->domain);
    }
}
