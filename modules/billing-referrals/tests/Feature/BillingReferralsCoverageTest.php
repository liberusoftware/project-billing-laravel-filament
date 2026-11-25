<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\App\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\App\Resources\Affiliates\Pages\EditAffiliate;
use App\Filament\App\Resources\Affiliates\Pages\ListAffiliates;
use App\Models\Affiliate;
use App\Models\AffiliateTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Invoice_Item;
use App\Models\Payment;
use App\Models\Products_Service;
use App\Models\User;
use App\Policies\AffiliatePolicy;
use App\Services\AffiliateService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BillingReferralsCoverageTest extends TestCase
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

    public function test_affiliate_and_transaction_relationships(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'code' => 'AFF-REL-001',
            'commission_rate' => 12.50,
            'status' => 'active',
            'total_earnings' => 0,
        ]);

        $this->assertInstanceOf(BelongsTo::class, $affiliate->user());
        $this->assertEquals($user->id, $affiliate->user->id);
        $this->assertInstanceOf(HasMany::class, $affiliate->payments());

        $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id, 'user_id' => $user->id]);
        $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'team_id' => $user->currentTeam->id]);

        $tx = AffiliateTransaction::create([
            'affiliate_id' => $affiliate->id,
            'invoice_id' => $invoice->id,
            'amount' => 25.00,
            'type' => 'commission',
            'status' => 'pending',
        ]);

        $this->assertInstanceOf(BelongsTo::class, $tx->affiliate());
        $this->assertEquals($affiliate->id, $tx->affiliate->id);
        $this->assertInstanceOf(BelongsTo::class, $tx->invoice());
        $this->assertEquals($invoice->id, $tx->invoice->id);
    }

    public function test_affiliate_policy_checks_all_permissions(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'code' => 'AFF-POL-001',
            'commission_rate' => 10.00,
            'status' => 'active',
            'total_earnings' => 0,
        ]);

        $policy = new AffiliatePolicy;

        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($policy->view($user, $affiliate));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->update($user, $affiliate));
        $this->assertFalse($policy->delete($user, $affiliate));
        $this->assertFalse($policy->deleteAny($user));
        $this->assertFalse($policy->forceDelete($user, $affiliate));
        $this->assertFalse($policy->forceDeleteAny($user));
        $this->assertFalse($policy->restore($user, $affiliate));
        $this->assertFalse($policy->restoreAny($user));
        $this->assertFalse($policy->replicate($user, $affiliate));
        $this->assertFalse($policy->reorder($user));

        $permissions = [
            'view_any_affiliate',
            'view_affiliate',
            'create_affiliate',
            'update_affiliate',
            'delete_affiliate',
            'delete_any_affiliate',
            'force_delete_affiliate',
            'force_delete_any_affiliate',
            'restore_affiliate',
            'restore_any_affiliate',
            'replicate_affiliate',
            'reorder_affiliate',
        ];
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'web');
            $user->givePermissionTo($perm);
        }

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $affiliate));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, $affiliate));
        $this->assertTrue($policy->delete($user, $affiliate));
        $this->assertTrue($policy->deleteAny($user));
        $this->assertTrue($policy->forceDelete($user, $affiliate));
        $this->assertTrue($policy->forceDeleteAny($user));
        $this->assertTrue($policy->restore($user, $affiliate));
        $this->assertTrue($policy->restoreAny($user));
        $this->assertTrue($policy->replicate($user, $affiliate));
        $this->assertTrue($policy->reorder($user));
    }

    public function test_affiliate_service_processes_reward_for_active_referrer(): void
    {
        $referrerUser = User::factory()->withPersonalTeam()->create();
        $team = $referrerUser->currentTeam;

        $affiliate = Affiliate::create([
            'user_id' => $referrerUser->id,
            'code' => 'AFF-SVC-001',
            'commission_rate' => 15.00,
            'status' => 'active',
            'total_earnings' => 0,
        ]);

        $buyerUser = User::factory()->withPersonalTeam()->create([
            'referred_by' => $affiliate->id,
        ]);

        $customer = Customer::factory()->create([
            'team_id' => $team->id,
            'user_id' => $buyerUser->id,
        ]);

        $product = Products_Service::factory()->create([
            'team_id' => $team->id,
            'base_price' => 200.00,
        ]);

        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'total_amount' => 200.00,
        ]);

        Invoice_Item::create([
            'invoice_id' => $invoice->id,
            'product_service_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 200.00,
            'total_price' => 200.00,
        ]);

        $payment = Payment::factory()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'amount' => 200.00,
        ]);

        app(AffiliateService::class)->processAffiliateReward($payment);

        $payment->refresh();
        $affiliate->refresh();

        $this->assertEquals($affiliate->id, $payment->affiliate_id);
        $this->assertEquals(30.00, (float) $payment->affiliate_commission);
        $this->assertEquals(30.00, (float) $affiliate->total_earnings);
        $this->assertDatabaseHas('affiliate_transactions', [
            'affiliate_id' => $affiliate->id,
            'invoice_id' => $invoice->id,
            'type' => 'commission',
        ]);
    }

    public function test_affiliate_filament_resource_pages_and_report_action(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->bootAppPanel($user);

        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'code' => 'AFF-FIL-001',
            'commission_rate' => 10.00,
            'status' => 'active',
            'total_earnings' => 125.50,
            'team_id' => $user->currentTeam->id,
        ]);

        Route::get('/test-affiliate-report', fn () => 'ok')->name('affiliate.report');

        Livewire::test(ListAffiliates::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$affiliate])
            ->callTableAction('generateReport', $affiliate)
            ->assertRedirect();

        Livewire::test(CreateAffiliate::class)
            ->assertSuccessful()
            ->fillForm([
                'user_id' => $user->id,
                'code' => 'AFF-FIL-002',
                'commission_rate' => 20.00,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet([
                'code' => 'AFF-FIL-001',
            ]);
    }
}
