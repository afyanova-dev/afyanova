<?php

declare(strict_types=1);

use App\Domains\BedWard\Domain\ValueObject\BedEndReason;

test('BedEndReason contains canonical closed set', function () {
    expect(BedEndReason::ALL)->toBe([
        'DISCHARGE',
        'TRANSFER',
        'DEATH',
        'CORRECTION',
    ]);
});

test('BedEndReason::isValid returns true for valid reason and false otherwise', function () {
    expect(BedEndReason::isValid('DISCHARGE'))->toBeTrue()
        ->and(BedEndReason::isValid('TRANSFER'))->toBeTrue()
        ->and(BedEndReason::isValid('DEATH'))->toBeTrue()
        ->and(BedEndReason::isValid('CORRECTION'))->toBeTrue()
        ->and(BedEndReason::isValid('discharge'))->toBeTrue()
        ->and(BedEndReason::isValid('OTHER'))->toBeFalse()
        ->and(BedEndReason::isValid(''))->toBeFalse();
});

test('BedEndReason::assert returns valid reason or throws InvalidArgumentException', function () {
    expect(BedEndReason::assert('DISCHARGE'))->toBe('DISCHARGE')
        ->and(BedEndReason::assert('TRANSFER'))->toBe('TRANSFER')
        ->and(BedEndReason::assert('DEATH'))->toBe('DEATH')
        ->and(BedEndReason::assert('CORRECTION'))->toBe('CORRECTION')
        ->and(BedEndReason::assert('discharge'))->toBe('DISCHARGE');

    expect(fn () => BedEndReason::assert('INVALID'))
        ->toThrow(InvalidArgumentException::class, 'Invalid BedEndReason [INVALID]. Permitted: DISCHARGE, TRANSFER, DEATH, CORRECTION.');
});
