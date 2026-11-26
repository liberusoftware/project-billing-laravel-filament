<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\Currencies\Pages\EditCurrency;
use App\Filament\Admin\Resources\Currencies\Pages\ListCurrencies;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Invoice_Item;
use App\Models\Products_Service;
use App\Models\TaxExemption;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\TaxService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Livewire\Livewire;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class BillingMoneyCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    private function bootAdminPanel(User $user): void
    {
        setPermissionsTeamId($user->currentTeam->id);
        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');
        $this->actingAs($user);

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $panel->boot();
        Filament::setTenant($user->currentTeam);
    }

    public function test_currency_filament_list_and_edit_pages(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->bootAdminPanel($user);

        $currency = Currency::create([
            'code' => 'CAD',
            'name' => 'Canadian Dollar',
            'symbol' => 'CA$',
            'exchange_rate' => 1.35,
            'decimal_precision' => 2,
            'is_enabled' => true,
            'is_base' => false,
        ]);

        Livewire::test(ListCurrencies::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$currency]);

        Livewire::test(EditCurrency::class, ['record' => $currency->getRouteKey()])
            ->assertSuccessful()
            ->assertFormSet([
                'code' => 'CAD',
                'name' => 'Canadian Dollar',
            ]);
    }

    public function test_currency_model_scopes_base_and_relations(): void
    {
        $usd = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'exchange_rate' => 1.00,
            'decimal_precision' => 2,
            'is_enabled' => true,
            'is_base' => true,
        ]);
        Currency::create([
            'code' => 'JPY',
            'name' => 'Japanese Yen',
            'symbol' => '¥',
            'exchange_rate' => 150.00,
            'decimal_precision' => 0,
            'is_enabled' => false,
            'is_base' => false,
        ]);

        $this->assertEquals($usd->id, Currency::base()?->id);
        $this->assertCount(1, Currency::enabled()->get());
        $this->assertInstanceOf(HasMany::class, $usd->invoices());
        $this->assertInstanceOf(HasMany::class, $usd->invoiceItems());
        $this->assertInstanceOf(HasMany::class, $usd->payments());
    }

    public function test_tax_exemption_and_tax_rate_models(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $customer = Customer::factory()->create(['team_id' => $team->id]);

        $exemption = TaxExemption::create([
            'customer_id' => $customer->id,
            'exemption_number' => 'EX-123',
            'reason' => 'Non-profit',
            'expiry_date' => now()->addMonth(),
            'is_active' => true,
        ]);

        $this->assertInstanceOf(BelongsTo::class, $exemption->customer());
        $this->assertEquals($customer->id, $exemption->customer->id);
        $this->assertTrue($exemption->isValid());

        $expired = TaxExemption::create([
            'customer_id' => $customer->id,
            'exemption_number' => 'EX-124',
            'reason' => 'Expired',
            'expiry_date' => now()->subDay(),
            'is_active' => true,
        ]);
        $this->assertFalse($expired->isValid());

        $noExpiry = TaxExemption::create([
            'customer_id' => $customer->id,
            'exemption_number' => 'EX-125',
            'reason' => 'Permanent',
            'expiry_date' => null,
            'is_active' => true,
        ]);
        $this->assertTrue($noExpiry->isValid());

        $inactiveExemption = TaxExemption::create([
            'customer_id' => $customer->id,
            'exemption_number' => 'EX-126',
            'reason' => 'Revoked',
            'expiry_date' => now()->addMonth(),
            'is_active' => false,
        ]);
        $this->assertFalse($inactiveExemption->isValid());

        $rate = TaxRate::create([
            'team_id' => $team->id,
            'name' => 'Standard VAT',
            'country' => 'GB',
            'rate' => 20.00,
            'is_active' => true,
            'effective_date' => now()->subDay(),
            'expiry_date' => now()->addMonth(),
            'threshold_amount' => 100.00,
            'threshold_rate' => 25.00,
        ]);

        $this->assertInstanceOf(BelongsTo::class, $rate->team());
        $this->assertTrue($rate->isValid());
        $this->assertCount(1, TaxRate::active()->where('id', $rate->id)->get());
        $this->assertEquals(20.00, (float) $rate->getEffectiveRate(50.00));
        $this->assertEquals(25.00, (float) $rate->getEffectiveRate(150.00));

        $rateWithoutThresholdRate = TaxRate::create([
            'team_id' => $team->id,
            'name' => 'Flat Threshold VAT',
            'country' => 'GB',
            'rate' => 15.00,
            'is_active' => true,
            'effective_date' => null,
            'expiry_date' => null,
            'threshold_amount' => 100.00,
            'threshold_rate' => null,
        ]);
        $this->assertTrue($rateWithoutThresholdRate->isValid());
        $this->assertEquals(15.00, (float) $rateWithoutThresholdRate->getEffectiveRate(150.00));

        $inactiveRate = TaxRate::create([
            'team_id' => $team->id,
            'name' => 'Inactive VAT',
            'country' => 'GB',
            'rate' => 10.00,
            'is_active' => false,
        ]);
        $this->assertFalse($inactiveRate->isValid());
    }

    public function test_currency_service_edge_case_guards(): void
    {
        $service = new CurrencyService();

        // Zero/negative exchange rate guard
        Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'exchange_rate' => 1.0,
            'decimal_precision' => 2,
            'is_enabled' => true,
            'is_base' => true,
        ]);
        Currency::create([
            'code' => 'BAD',
            'name' => 'Bad Currency',
            'exchange_rate' => 0.0,
            'decimal_precision' => 2,
            'is_enabled' => true,
            'is_base' => false,
        ]);

        try {
            $service->convert(100.0, 'USD', 'BAD');
            $this->fail('Expected unusable exchange rate exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no usable exchange rate', $e->getMessage());
        }

        // Recursive conversion guard
        $isProcessingProp = new ReflectionProperty(CurrencyService::class, 'isProcessing');
        $isProcessingProp->setValue(null, true);
        try {
            $service->convert(100.0, 'USD', 'BAD');
            $this->fail('Expected recursive conversion exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Recursive currency conversion', $e->getMessage());
        } finally {
            $isProcessingProp->setValue(null, false);
        }

        // Max depth guard
        $calcMethod = new ReflectionMethod(CurrencyService::class, 'calculateRate');
        try {
            $calcMethod->invoke($service, 100.0, 'USD', 'BAD', 5);
            $this->fail('Expected max depth exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Maximum currency conversion depth', $e->getMessage());
        }

        // Circular reference guard
        $processedProp = new ReflectionProperty(CurrencyService::class, 'processedCurrencies');
        $processedProp->setValue($service, ['USD-BAD' => true]);
        try {
            $calcMethod->invoke($service, 100.0, 'USD', 'BAD', 0);
            $this->fail('Expected circular reference exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Circular reference', $e->getMessage());
        }
    }

    public function test_tax_service_api_success_fallback_and_threshold_without_threshold_rate(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $customer = Customer::factory()->create([
            'team_id' => $team->id,
            'country' => 'US',
            'state' => 'CA',
        ]);
        $product = Products_Service::factory()->create([
            'team_id' => $team->id,
            'type' => 'hosting',
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
            'currency' => 'USD',
        ]);

        // 1. External Tax API success path (including threshold_amount set without threshold_rate)
        // 2. External Tax API failure (500) falls back to database
        config(['services.tax_api' => [
            'enabled' => true,
            'api_key' => 'secret-key',
            'url' => 'https://tax.example.test/rates',
        ]]);
        Cache::flush();

        Http::fakeSequence('https://tax.example.test/rates*')
            ->push([
                [
                    'rate' => 10.0,
                    'type' => 'hosting',
                    'country' => 'US',
                    'state' => 'CA',
                    'threshold_amount' => 100.0,
                    'threshold_rate' => null,
                ],
            ], 200)
            ->push(['error' => 'down'], 500);

        $tax = (new TaxService())->calculateTax($invoice->fresh(['customer', 'items.productService']));
        $this->assertEquals(20.00, $tax);

        // Second call hits the 500 response and falls back to database rates
        Cache::flush();
        TaxRate::create([
            'team_id' => $team->id,
            'name' => 'CA Hosting Tax',
            'country' => 'US',
            'state' => 'CA',
            'service_type' => 'hosting',
            'rate' => 8.0,
            'is_active' => true,
        ]);

        $fallbackTax = (new TaxService())->calculateTax($invoice->fresh(['customer', 'items.productService']));
        $this->assertEquals(16.00, $fallbackTax);
    }
}
