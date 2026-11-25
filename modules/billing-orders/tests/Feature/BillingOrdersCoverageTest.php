<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\OrderFormTemplates\OrderFormTemplateResource;
use App\Filament\Admin\Resources\OrderFormTemplates\Pages\EditOrderFormTemplate;
use App\Filament\Admin\Resources\OrderFormTemplates\Pages\ListOrderFormTemplates;
use App\Models\Customer;
use App\Models\HostingFulfillment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderFormTemplate;
use App\Models\Products_Service;
use App\Models\Quote;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Policies\QuotePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BillingOrdersCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_and_template_relationships_and_hosting_mapping(): void
    {
        $customer = Customer::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Pro Hosting',
            'code' => 'pro-host-1',
            'price' => 29.99,
            'currency' => 'USD',
            'is_active' => true,
            'trial_days' => 0,
        ]);
        $product = Products_Service::factory()->create(['type' => 'hosting']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'product_service_id' => $product->id,
        ]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
        ]);

        $template = OrderFormTemplate::factory()->create([
            'config' => [
                'plan_ids' => [$plan->id],
                'hosting_packages' => [
                    ['plan_id' => 99999, 'product_service_id' => 88888],
                    ['plan_id' => $plan->id, 'product_service_id' => $product->id],
                ],
            ],
        ]);

        $order = Order::create([
            'order_form_template_id' => $template->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'submitted_data' => ['subscription_plan_id' => $plan->id],
        ]);

        $this->assertSame($product->id, $template->hostingProductIdForPlan($plan->id));
        $this->assertNull($template->hostingProductIdForPlan(12345));
        $this->assertCount(1, $template->orders);
        $this->assertTrue($template->orders->first()->is($order));

        $this->assertTrue($order->template->is($template));
        $this->assertTrue($order->customer->is($customer));
        $this->assertTrue($order->subscription->is($subscription));
        $this->assertTrue($order->invoice->is($invoice));
        $this->assertSame(HostingFulfillment::class, $order->fulfillment()->getRelated()::class);
    }

    public function test_quote_policy_delegates_to_user_permissions(): void
    {
        $user = User::factory()->create();
        $quote = new Quote();
        $policy = new QuotePolicy();

        $methods = [
            'viewAny' => ['view_any_quote', []],
            'view' => ['view_quote', [$quote]],
            'create' => ['create_quote', []],
            'update' => ['update_quote', [$quote]],
            'delete' => ['delete_quote', [$quote]],
            'deleteAny' => ['delete_any_quote', []],
            'forceDelete' => ['force_delete_quote', [$quote]],
            'forceDeleteAny' => ['force_delete_any_quote', []],
            'restore' => ['restore_quote', [$quote]],
            'restoreAny' => ['restore_any_quote', []],
            'replicate' => ['replicate_quote', [$quote]],
            'reorder' => ['reorder_quote', []],
        ];

        foreach ($methods as $method => [$permission, $args]) {
            $this->assertFalse($policy->{$method}($user, ...$args));
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
            $this->assertTrue($policy->{$method}($user->fresh(), ...$args));
        }
    }

    public function test_order_form_template_admin_filament_pages_and_table(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $team = Team::factory()->create(['user_id' => $admin->id]);
        $admin->teams()->attach($team);
        $admin->update(['current_team_id' => $team->id]);

        $template = OrderFormTemplate::factory()->create([
            'name' => 'Default Storefront',
            'slug' => 'default-storefront',
            'is_active' => true,
        ]);

        $this->actingAs($admin);
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($team);
        $panel->boot();

        $this->assertSame([], OrderFormTemplateResource::getRelations());

        Livewire::test(ListOrderFormTemplates::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$template]);

        Livewire::test(EditOrderFormTemplate::class, ['record' => $template->getRouteKey()])
            ->assertSuccessful();
    }
}
