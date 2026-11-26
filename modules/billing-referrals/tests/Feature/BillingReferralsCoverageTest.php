<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\App\Resources\Affiliates\AffiliateResource;
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
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as DbSchema;
use Liberu\Foundation\RolesPermissions\Models\Permission;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class BillingReferralsCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);

        parent::tearDown();
    }

    private function ensurePaymentAffiliateColumns(): void
    {
        if (! DbSchema::hasColumn('payments', 'affiliate_id')) {
            DbSchema::table('payments', function (Blueprint $table): void {
                $table->unsignedBigInteger('affiliate_id')->nullable();
            });
        }

        if (! DbSchema::hasColumn('payments', 'affiliate_commission')) {
            DbSchema::table('payments', function (Blueprint $table): void {
                $table->decimal('affiliate_commission', 10, 2)->nullable();
            });
        }
    }

    public function test_affiliate_and_transaction_relationships(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'code' => 'AFF-REL-001',
            'commission_rate' => 12.50,
            'status' => 'active',
        ]);

        $this->assertInstanceOf(BelongsTo::class, $affiliate->user());
        $this->assertEquals($user->id, $affiliate->user->id);
        $this->assertInstanceOf(HasMany::class, $affiliate->payments());

        $customer = Customer::factory()->create(['team_id' => $user->currentTeam->id]);
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
        setPermissionsTeamId($user->currentTeam->id);

        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'code' => 'AFF-POL-001',
            'commission_rate' => 10.00,
            'status' => 'active',
        ]);

        $policy = new AffiliatePolicy();

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
        $this->ensurePaymentAffiliateColumns();

        $referrerUser = User::factory()->withPersonalTeam()->create();
        $team = $referrerUser->currentTeam;

        $affiliate = Affiliate::create([
            'user_id' => $referrerUser->id,
            'code' => 'AFF-SVC-001',
            'commission_rate' => 15.00,
            'status' => 'active',
        ]);

        $buyerUser = User::factory()->withPersonalTeam()->create([
            'referred_by' => $affiliate->id,
        ]);

        $customer = Customer::factory()->create([
            'team_id' => $team->id,
        ]);
        $customer->setRelation('user', $buyerUser);

        $product = Products_Service::factory()->create([
            'team_id' => $team->id,
            'base_price' => 200.00,
        ]);

        $invoice = Invoice::factory()->create([
            'team_id' => $team->id,
            'customer_id' => $customer->id,
            'total_amount' => 200.00,
        ]);
        $invoice->setRelation('customer', $customer);

        Invoice_Item::create([
            'invoice_id' => $invoice->id,
            'product_service_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 200.00,
            'total_price' => 200.00,
            'currency' => 'USD',
        ]);

        $payment = Payment::factory()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'amount' => 200.00,
        ]);
        $payment->setRelation('invoice', $invoice);

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

    public function test_affiliate_service_skips_inactive_or_missing_referrer(): void
    {
        $referrerUser = User::factory()->withPersonalTeam()->create();
        $team = $referrerUser->currentTeam;

        $inactiveAffiliate = Affiliate::create([
            'user_id' => $referrerUser->id,
            'code' => 'AFF-SVC-INACTIVE',
            'commission_rate' => 15.00,
            'status' => 'inactive',
        ]);

        foreach ([null, $inactiveAffiliate->id] as $referredBy) {
            $buyerUser = User::factory()->withPersonalTeam()->create([
                'referred_by' => $referredBy,
            ]);

            $customer = Customer::factory()->create(['team_id' => $team->id]);
            $customer->setRelation('user', $buyerUser);

            $invoice = Invoice::factory()->create([
                'team_id' => $team->id,
                'customer_id' => $customer->id,
                'total_amount' => 100.00,
            ]);
            $invoice->setRelation('customer', $customer);

            $payment = Payment::factory()->create([
                'team_id' => $team->id,
                'invoice_id' => $invoice->id,
                'amount' => 100.00,
            ]);
            $payment->setRelation('invoice', $invoice);

            app(AffiliateService::class)->processAffiliateReward($payment);
        }

        $this->assertSame(0, AffiliateTransaction::query()->count());
        $this->assertEquals(0.00, (float) $inactiveAffiliate->fresh()->total_earnings);
    }

    public function test_affiliate_filament_resource_form_table_actions_and_pages(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'code' => 'AFF-FIL-001',
            'commission_rate' => 10.00,
            'status' => 'active',
        ]);
        $affiliate->forceFill([
            'total_earnings' => 125.50,
            'team_id' => $user->currentTeam->id,
        ])->save();

        // Form schema and formatStateUsing closure for total_earnings
        $editPage = new EditAffiliate();
        $schema = AffiliateResource::form(
            Schema::make($editPage)->model(Affiliate::class)->statePath('data')
        );
        $this->assertCount(5, $schema->getComponents());

        $schema->fill([
            'user_id' => $user->id,
            'code' => 'AFF-FIL-001',
            'commission_rate' => 10.00,
            'status' => 'active',
            'total_earnings' => 125.50,
        ]);
        $this->assertSame('$125.50', $editPage->data['total_earnings']);

        // Table schema and generateReport record action closure
        $livewire = Mockery::mock(HasTable::class);
        $table = AffiliateResource::table(Table::make($livewire));

        $this->assertCount(5, $table->getColumns());
        $this->assertCount(0, $table->getFilters());
        $this->assertCount(2, $table->getRecordActions());
        $this->assertCount(1, $table->getToolbarActions());

        Route::get('/test-affiliate-report', fn (): string => 'ok')->name('affiliate.report');
        app('router')->getRoutes()->refreshNameLookups();

        /** @var Action|null $generateReportAction */
        $generateReportAction = collect($table->getRecordActions())
            ->first(fn ($action): bool => $action instanceof Action && $action->getName() === 'generateReport');

        $this->assertInstanceOf(Action::class, $generateReportAction);
        $response = app()->call($generateReportAction->getActionFunction(), ['record' => $affiliate]);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('/test-affiliate-report', $response->getTargetUrl());

        // Resource metadata
        $this->assertSame([], AffiliateResource::getRelations());
        $this->assertArrayHasKey('index', AffiliateResource::getPages());
        $this->assertArrayHasKey('create', AffiliateResource::getPages());
        $this->assertArrayHasKey('edit', AffiliateResource::getPages());

        // ListAffiliates & EditAffiliate page header actions
        $listPage = new ListAffiliates();
        $listActions = (new ReflectionMethod($listPage, 'getActions'))->invoke($listPage);
        $this->assertCount(1, $listActions);
        $this->assertInstanceOf(CreateAction::class, $listActions[0]);

        $editActions = (new ReflectionMethod($editPage, 'getActions'))->invoke($editPage);
        $this->assertCount(1, $editActions);
        $this->assertInstanceOf(DeleteAction::class, $editActions[0]);
    }
}
