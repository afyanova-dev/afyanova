<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\ProblemCode;

/*
 | PHASE 6 (C.4) unit gate — the ProblemCode value object (12-clinical-domain.md
 | §24, §102; ADR-140 D-G). Open strings, both-or-neither, no terminology.
 */

test('of() with a matching code + code_system is coded', function () {
    $c = ProblemCode::of(code: 'I10', codeSystem: 'ICD-10');

    expect($c->code)->toBe('I10')
        ->and($c->codeSystem)->toBe('ICD-10')
        ->and($c->isCoded())->toBeTrue();
});

test('none() and of(null, null) are un-coded', function () {
    expect(ProblemCode::none()->isCoded())->toBeFalse()
        ->and(ProblemCode::of()->code)->toBeNull()
        ->and(ProblemCode::of()->codeSystem)->toBeNull();
});

test('a code without a code_system is rejected (ADR-140 D-G)', function () {
    expect(fn () => ProblemCode::of(code: 'I10'))->toThrow(InvalidArgumentException::class);
});

test('a code_system without a code is rejected (ADR-140 D-G)', function () {
    expect(fn () => ProblemCode::of(codeSystem: 'ICD-10'))->toThrow(InvalidArgumentException::class);
});

test('blank strings normalise to null (both -> un-coded)', function () {
    $c = ProblemCode::of(code: '   ', codeSystem: '');
    expect($c->isCoded())->toBeFalse();
});

test('values are trimmed', function () {
    $c = ProblemCode::of(code: '  I10 ', codeSystem: ' ICD-10 ');
    expect($c->code)->toBe('I10')->and($c->codeSystem)->toBe('ICD-10');
});

test('an over-long code / code_system is rejected', function (array $args) {
    expect(fn () => ProblemCode::of(...$args))->toThrow(InvalidArgumentException::class);
})->with([
    'long code' => [['code' => str_repeat('x', 101), 'codeSystem' => 'ICD-10']],
    'long system' => [['code' => 'I10', 'codeSystem' => str_repeat('y', 101)]],
]);

test('an open non-standard code system string is accepted (no enum / catalogue)', function () {
    expect(ProblemCode::of(code: 'HTN', codeSystem: 'local-registry')->codeSystem)->toBe('local-registry');
});

test('the value object is final readonly', function () {
    $r = new ReflectionClass(ProblemCode::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
