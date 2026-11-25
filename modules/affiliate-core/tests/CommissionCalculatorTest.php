<?php

declare(strict_types=1);

use Liberu\Affiliate\Contracts\Contracts\CommissionCalculatorContract;
use Liberu\Affiliate\Core\Services\CommissionCalculator;
use Tests\TestCase;

uses(TestCase::class);

it('registers the commission calculator contract in the container', function () {
    expect(app(CommissionCalculatorContract::class))->toBeInstanceOf(CommissionCalculator::class);
});

it('returns zero for non-positive net amounts', function () {
    $calculator = app(CommissionCalculatorContract::class);

    expect($calculator->calculateMinor(0, 1500, 100))->toBe(0)
        ->and($calculator->calculateMinor(-500, 1500, 100))->toBe(0);
});

it('calculates percentage basis points and flat commission in minor units', function () {
    $calculator = app(CommissionCalculatorContract::class);

    expect($calculator->calculateMinor(10000, 1500))->toBe(1500)
        ->and($calculator->calculateMinor(10000, 1500, 250))->toBe(1750)
        ->and($calculator->calculateMinor(333, 1000))->toBe(33);
});
