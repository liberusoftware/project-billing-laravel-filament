<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\App\Resources\ProductsServices\Pages\CreateProductsService;
use App\Filament\App\Resources\ProductsServices\Pages\EditProductsService;
use App\Filament\App\Resources\ProductsServices\Pages\ListProductsServices;
use App\Models\PackageGroup;
use App\Models\Products_Service;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tld;
use App\Models\UsageRecord;
use App\Models\User;
use App\Policies\Products_ServicePolicy;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BillingCatalogCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function bootAppPanel(User $user): void
    {
        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');
        $this->actingAs($user);

        $panel = Filament::getPanel('app');
        Filament::setCurrentPanel($panel);
        $panel->boot();
        Filament::setTenant($user->currentTeam);
    }

    public function test_package_group_team_relation_and_products_service_methods(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $group = PackageGroup::create([
            'team_id' => $user->currentTeam->id,
            'name' => 'Hosting Packages',
            'is_active' => true,
        ]);

        $this->assertInstanceOf(BelongsTo::class, $group->team());
        $this->assertEquals($user->currentTeam->id, $group->team->id);

        $fixedProduct = Products_Service::factory()->create([
            'team_id' => $user->currentTeam->id,
            'pricing_model' => 'fixed',
        ]);
        $this->assertInstanceOf(HasMany::class, $fixedProduct->invoiceItems());
        $this->assertInstanceOf(HasManyThrough::class, $fixedProduct->usageRecords());
        $this->assertSame([], $fixedProduct->getUsageMetrics());

        $usageProduct = Products_Service::factory()->create([
            'team_id' => $user->currentTeam->id,
            'pricing_model' => 'usage_based',
            'custom_pricing_data' => [
                'usage_config' => [
                    'bandwidth_gb' => ['rate' => 0.10],
                ],
            ],
        ]);
        $this->assertSame(['bandwidth_gb'], $usageProduct->getUsageMetrics());

        $subscription = Subscription::factory()->create([
            'team_id' => $user->currentTeam->id,
        ]);

        $record = $usageProduct->recordUsage($subscription->id, 'bandwidth_gb', 50);
        $this->assertInstanceOf(UsageRecord::class, $record);
        $this->assertEquals('bandwidth_gb', $record->metric_name);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid usage metric: unknown_metric');
        $usageProduct->recordUsage($subscription->id, 'unknown_metric', 10);
    }

    public function test_products_service_policy_checks_all_permissions(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $product = Products_Service::factory()->create(['team_id' => $user->currentTeam->id]);
        $policy = new Products_ServicePolicy;

        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($policy->view($user, $product));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $product));
        $this->assertFalse($policy->delete($user, $product));
        $this->assertFalse($policy->deleteAny($user));
        $this->assertFalse($policy->forceDelete($user, $product));
        $this->assertFalse($policy->forceDeleteAny($user));
        $this->assertFalse($policy->restore($user, $product));
        $this->assertFalse($policy->restoreAny($user));
        $this->assertFalse($policy->replicate($user, $product));
        $this->assertFalse($policy->reorder($user));

        $permissions = [
            'view_any_products::service',
            'view_products::service',
            'create_products::service',
            'update_products::service',
            'delete_products::service',
            'delete_any_products::service',
            'force_delete_products::service',
            'force_delete_any_products::service',
            'restore_products::service',
            'restore_any_products::service',
            'replicate_products::service',
            'reorder_products::service',
        ];
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'web');
            $user->givePermissionTo($perm);
        }

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $product));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, $product));
        $this->assertTrue($policy->delete($user, $product));
        $this->assertTrue($policy->deleteAny($user));
        $this->assertTrue($policy->forceDelete($user, $product));
        $this->assertTrue($policy->forceDeleteAny($user));
        $this->assertTrue($policy->restore($user, $product));
        $this->assertTrue($policy->restoreAny($user));
        $this->assertTrue($policy->replicate($user, $product));
        $this->assertTrue($policy->reorder($user));
    }

    public function test_package_group_api_controller_full_lifecycle(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Sanctum::actingAs($user, ['package-groups:read', 'package-groups:write']);

        $storeResp = $this->postJson(route('package-groups.store'), [
            'name' => 'VPS Plans',
            'description' => 'Virtual private servers',
            'sort_order' => 1,
            'is_active' => true,
        ])->assertCreated();

        $groupId = $storeResp->json('data.id');

        $this->getJson(route('package-groups.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson(route('package-groups.show', $groupId))
            ->assertOk()
            ->assertJsonPath('data.name', 'VPS Plans');

        $this->patchJson(route('package-groups.update', $groupId), [
            'name' => 'Cloud VPS Plans',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Cloud VPS Plans');

        $plan1 = SubscriptionPlan::create([
            'name' => 'VPS Basic',
            'code' => 'vps-basic',
            'price' => 10.00,
            'billing_interval' => 'month',
            'interval_count' => 1,
        ]);
        $plan2 = SubscriptionPlan::create([
            'name' => 'VPS Pro',
            'code' => 'vps-pro',
            'price' => 25.00,
            'billing_interval' => 'month',
            'interval_count' => 1,
        ]);

        $this->postJson("/api/v1/package-groups/{$groupId}/packages", [
            'subscription_plan_id' => $plan1->id,
            'sort_order' => 0,
        ])->assertOk();

        $this->postJson("/api/v1/package-groups/{$groupId}/packages", [
            'subscription_plan_id' => $plan2->id,
            'sort_order' => 1,
        ])->assertOk();

        $this->postJson("/api/v1/package-groups/{$groupId}/reorder", [
            'plan_ids' => [$plan2->id, $plan1->id],
        ])->assertOk();

        $this->deleteJson("/api/v1/package-groups/{$groupId}/packages/{$plan1->id}")
            ->assertOk();

        $this->deleteJson(route('package-groups.destroy', $groupId))
            ->assertNoContent();
    }

    public function test_products_service_filament_resource_pages(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->bootAppPanel($user);

        $tld = Tld::create([
            'name' => '.com',
            'registration_price' => 12.00,
            'renewal_price' => 12.00,
            'transfer_price' => 12.00,
        ]);

        $product = Products_Service::factory()->create([
            'team_id' => $user->currentTeam->id,
            'name' => 'Shared Hosting',
            'type' => 'hosting',
            'pricing_model' => 'fixed',
            'base_price' => 9.99,
        ]);

        Livewire::test(ListProductsServices::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$product]);

        Livewire::test(CreateProductsService::class)
            ->assertSuccessful()
            ->fillForm([
                'name' => 'Domain Registration',
                'description' => 'Register a .com domain',
                'base_price' => 14.99,
                'type' => 'domain',
                'pricing_model' => 'tiered',
                'tld_id' => $tld->id,
                'markup_type' => 'percentage',
                'markup_value' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(EditProductsService::class, ['record' => $product->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet([
                'name' => 'Shared Hosting',
            ]);
    }
}
