<?php

declare(strict_types=1);

use App\Domains\Billing\Domain\Money;

test('Money::multiply rounds HALF-UP to scale-2 (ADR-189 C5)', function () {
    expect(Money::multiply('500.00', '10'))->toBe('5000.00')
        ->and(Money::multiply('3.33', '3'))->toBe('9.99')
        ->and(Money::multiply('10.00', '2.5'))->toBe('25.00')
        // 10.01 * 1.5 = 15.015 -> HALF-UP -> 15.02
        ->and(Money::multiply('10.01', '1.5'))->toBe('15.02')
        // 10.00 * 0.125 = 1.25 exactly
        ->and(Money::multiply('10.00', '0.125'))->toBe('1.25')
        // a boundary: 1.00 * 0.005 = 0.005 -> HALF-UP -> 0.01
        ->and(Money::multiply('1.00', '0.005'))->toBe('0.01')
        // just below the boundary: 1.00 * 0.0049 = 0.0049 -> 0.00
        ->and(Money::multiply('1.00', '0.0049'))->toBe('0.00');

    expect(fn () => Money::multiply('10.00', 'x'))->toThrow(InvalidArgumentException::class);
});
