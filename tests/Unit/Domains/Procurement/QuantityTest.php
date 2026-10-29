<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Quantity;

test('of normalises to fixed scale 4', function () {
    expect(Quantity::of('10'))->toBe('10.0000')
        ->and(Quantity::of('2.5'))->toBe('2.5000');
});

test('of rejects non-numeric', function () {
    expect(fn () => Quantity::of('abc'))->toThrow(InvalidArgumentException::class);
});

test('add accumulates at scale 4', function () {
    expect(Quantity::add('0', '7'))->toBe('7.0000')
        ->and(Quantity::add('7.0000', '3.5'))->toBe('10.5000');
});

test('gte compares at scale 4', function () {
    expect(Quantity::gte('10', '10'))->toBeTrue()
        ->and(Quantity::gte('10.0001', '10'))->toBeTrue()
        ->and(Quantity::gte('9.9999', '10'))->toBeFalse();
});

test('isPositive', function () {
    expect(Quantity::isPositive('0.0001'))->toBeTrue()
        ->and(Quantity::isPositive('0'))->toBeFalse();
});
