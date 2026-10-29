<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;

/*
 | PHASE 6 (C.3) unit gate — the DiagnosisCode value object
 | (12-clinical-domain.md §24, §102; ADR-139 D2). The ONLY rule: the pairing
 |   code absent  -> code_system absent
 |   code present -> code_system required
 | Open strings; no enum, no terminology validation.
 */

test('an un-coded diagnosis (both null) is valid', function () {
    expect(DiagnosisCode::none())->code->toBeNull()->codeSystem->toBeNull();
    expect(DiagnosisCode::of())->code->toBeNull()->codeSystem->toBeNull();
    expect(DiagnosisCode::none()->isCoded())->toBeFalse();
});

test('a code with a code_system is valid and trimmed', function () {
    $c = DiagnosisCode::of(code: '  B54 ', codeSystem: '  ICD-10 ');
    expect($c->code)->toBe('B54')->and($c->codeSystem)->toBe('ICD-10')->and($c->isCoded())->toBeTrue();
});

test('any open string is accepted as a code system — no enum, no terminology check', function (string $system) {
    expect(DiagnosisCode::of(code: 'X', codeSystem: $system)->codeSystem)->toBe($system);
})->with(['ICD-10', 'ICD-11', 'SNOMED CT', 'local', 'house-codes-2024', 'anything']);

test('a code without a code_system is rejected (ADR-139 D2)', function () {
    expect(fn () => DiagnosisCode::of(code: 'B54'))->toThrow(InvalidArgumentException::class, 'code_system');
    expect(fn () => DiagnosisCode::of(code: 'B54', codeSystem: '   '))->toThrow(InvalidArgumentException::class);
});

test('a code_system without a code is rejected (ADR-139 D2)', function () {
    expect(fn () => DiagnosisCode::of(codeSystem: 'ICD-10'))->toThrow(InvalidArgumentException::class, 'without a code');
    expect(fn () => DiagnosisCode::of(code: '  ', codeSystem: 'ICD-10'))->toThrow(InvalidArgumentException::class);
});

test('it rejects an over-long code / code system', function () {
    expect(fn () => DiagnosisCode::of(code: str_repeat('a', 101), codeSystem: 'ICD-10'))->toThrow(InvalidArgumentException::class);
    expect(fn () => DiagnosisCode::of(code: 'B54', codeSystem: str_repeat('a', 101)))->toThrow(InvalidArgumentException::class);
});

test('the VO is final readonly with no enumerated cases', function () {
    $r = new ReflectionClass(DiagnosisCode::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue()->and($r->isEnum())->toBeFalse();
});
