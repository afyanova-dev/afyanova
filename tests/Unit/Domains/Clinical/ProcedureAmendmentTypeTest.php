<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\ProcedureAmendmentType;

/*
 | PHASE 6 (CP.1) unit gate — ProcedureAmendmentType (ADR-146 D-M): the
 | ADR-134 ClinicalNoteAmendmentType shape, reused for procedures as a backend
 | `final class` (NOT `#[TypeScript]` — no PHASE-6 consumer, unlike C.1's enum).
 */

test('ALL is exactly {AMENDMENT, ADDENDUM, CORRECTION}', function () {
    expect(ProcedureAmendmentType::ALL)->toBe(['AMENDMENT', 'ADDENDUM', 'CORRECTION']);
});

test('assert() normalizes case/whitespace and accepts every member', function () {
    foreach (ProcedureAmendmentType::ALL as $value) {
        expect(ProcedureAmendmentType::assert(strtolower($value)))->toBe($value);
    }
});

test('assert() rejects an out-of-set value', function () {
    expect(fn () => ProcedureAmendmentType::assert('RETRACTION'))
        ->toThrow(InvalidArgumentException::class, 'Invalid ProcedureAmendmentType');
});

test('isValid() mirrors assert() without throwing', function () {
    expect(ProcedureAmendmentType::isValid('correction'))->toBeTrue()
        ->and(ProcedureAmendmentType::isValid('RETRACTION'))->toBeFalse();
});
