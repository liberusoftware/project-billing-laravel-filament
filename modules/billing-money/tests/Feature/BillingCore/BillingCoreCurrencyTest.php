<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Billing\Core\Actions\CalculateTax;
use Liberu\Billing\Core\Actions\ConvertCurrency;
use Liberu\Billing\Core\Models\BillingCurrency;
use Liberu\Billing\Core\Models\BillingTaxExemption;
use Liberu\Billing\Core\Models\BillingTaxProfile;

uses(RefreshDatabase::class);

it('converts currencies through team-scoped enabled rates', function (): void {
    BillingCurrency::query()->create(['team_id' => 10, 'code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2, 'enabled' => true, 'exchange_rate' => 1]);
    BillingCurrency::query()->create(['team_id' => 10, 'code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2, 'enabled' => true, 'exchange_rate' => 0.9]);

    expect(app(ConvertCurrency::class)->execute(10, 10, 'USD', 'EUR'))->toMatchArray(['amount' => 9.0, 'from' => 'USD', 'to' => 'EUR']);
});

it('rejects disabled or unavailable currencies', function (): void {
    BillingCurrency::query()->create(['team_id' => 10, 'code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2, 'enabled' => false, 'exchange_rate' => 1]);

    expect(fn () => app(ConvertCurrency::class)->execute(10, 10, 'USD', 'USD'))
        ->toThrow(InvalidArgumentException::class, 'Currency is unavailable: USD.');
});

it('calculates exclusive and inclusive tax from an enabled jurisdiction profile', function (): void {
    BillingTaxProfile::query()->create(['team_id' => 10, 'name' => 'UK VAT', 'rate' => 20, 'jurisdiction' => 'GB', 'inclusive' => false, 'enabled' => true]);

    expect(app(CalculateTax::class)->execute(10, 100, 'GB'))->toMatchArray(['subtotal' => 100.0, 'tax' => 20.0, 'total' => 120.0]);

    BillingTaxProfile::query()->update(['inclusive' => true]);
    expect(app(CalculateTax::class)->execute(10, 120, 'GB'))->toMatchArray(['subtotal' => 100.0, 'tax' => 20.0, 'total' => 120.0]);
});

it('applies tiered tax rates and active customer exemptions', function (): void {
    BillingTaxProfile::query()->create(['team_id' => 10, 'name' => 'Tiered VAT', 'rate' => 10, 'threshold_amount' => 100, 'threshold_rate' => 20, 'enabled' => true]);

    expect(app(CalculateTax::class)->execute(10, 200))->toMatchArray(['tax' => 30.0, 'total' => 230.0, 'exempt' => false]);

    BillingTaxExemption::query()->create(['team_id' => 10, 'customer_id' => 55, 'enabled' => true]);

    expect(app(CalculateTax::class)->execute(10, 200, null, 55))->toMatchArray(['tax' => 0.0, 'total' => 200.0, 'exempt' => true]);
});

it('rejects conversion without a valid team or positive rates', function (): void {
    $convert = app(ConvertCurrency::class);
    expect(fn () => $convert->execute(0, 10, 'EUR', 'USD'))
        ->toThrow(InvalidArgumentException::class, 'A team and currency codes are required.');

    BillingCurrency::query()->create(['team_id' => 10, 'code' => 'EUR', 'name' => 'Euro', 'enabled' => true, 'exchange_rate' => 0]);
    expect(fn () => $convert->execute(10, 10, 'EUR', 'USD'))
        ->toThrow(InvalidArgumentException::class, 'Currency has no usable exchange rate: EUR.');

    BillingCurrency::query()->where('code', 'EUR')->update(['exchange_rate' => 0.9]);
    BillingCurrency::query()->create(['team_id' => 10, 'code' => 'USD', 'name' => 'US Dollar', 'enabled' => true, 'exchange_rate' => 1]);
    cache()->put('billing.currency.rate.EUR.USD', -1);
    expect(fn () => $convert->execute(10, 10, 'EUR', 'USD'))
        ->toThrow(InvalidArgumentException::class, 'Currency exchange rates must be positive.');
});

it('requires a valid taxable amount and returns zero without an applicable profile', function (): void {
    $calculate = app(CalculateTax::class);

    expect(fn () => $calculate->execute(10, -1))
        ->toThrow(InvalidArgumentException::class, 'A team and non-negative amount are required.');

    expect($calculate->execute(10, 100, 'GB'))->toMatchArray([
        'subtotal' => 100.0, 'tax' => 0.0, 'total' => 100.0, 'exempt' => false,
    ]);
});
