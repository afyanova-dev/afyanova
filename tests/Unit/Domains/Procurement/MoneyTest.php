<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Money;

test('of normalises to fixed scale 2 (bcmath truncates)', function () {
    expect(Money::of('10'))->toBe('10.00')
        ->and(Money::of('10.5'))->toBe('10.50')
        ->and(Money::of('10.999'))->toBe('10.99');
});

test('of rejects non-numeric', function () {
    expect(fn () => Money::of('abc'))->toThrow(InvalidArgumentException::class);
});

test('multiplyByQuantity: price × quantity at scale 2', function () {
    expect(Money::multiplyByQuantity('12500.00', '3'))->toBe('37500.00')
        ->and(Money::multiplyByQuantity('1.25', '4'))->toBe('5.00')
        ->and(Money::multiplyByQuantity('0.333', '3'))->toBe('0.99');
});

test('add sums at scale 2', function () {
    expect(Money::add('10.00', '2.50'))->toBe('12.50');
});

test('isNegative', function () {
    expect(Money::isNegative('-0.01'))->toBeTrue()
        ->and(Money::isNegative('0'))->toBeFalse()
        ->and(Money::isNegative('5.00'))->toBeFalse();
});
