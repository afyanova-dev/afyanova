<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\ProcedureLaterality;

/*
 | PHASE 6 (CP.1) unit gate — ProcedureLaterality (ADR-146 D-H): a CLOSED coded
 | set (unlike the open `site` string) because wrong-laterality is a
 | never-event safety concern.
 */

test('ALL is exactly {LEFT, RIGHT, BILATERAL, NOT_APPLICABLE, UNKNOWN}', function () {
    expect(ProcedureLaterality::ALL)->toBe(['LEFT', 'RIGHT', 'BILATERAL', 'NOT_APPLICABLE', 'UNKNOWN']);
});

test('assert() normalizes case/whitespace and accepts every member', function () {
    foreach (ProcedureLaterality::ALL as $value) {
        expect(ProcedureLaterality::assert(strtolower($value)))->toBe($value);
        expect(ProcedureLaterality::assert("  {$value}  "))->toBe($value);
    }
});

test('assert() rejects an out-of-set value', function () {
    expect(fn () => ProcedureLaterality::assert('BOTH'))
        ->toThrow(InvalidArgumentException::class, 'Invalid ProcedureLaterality');
});

test('isValid() mirrors assert() without throwing', function () {
    expect(ProcedureLaterality::isValid('left'))->toBeTrue()
        ->and(ProcedureLaterality::isValid('BOTH'))->toBeFalse();
});
