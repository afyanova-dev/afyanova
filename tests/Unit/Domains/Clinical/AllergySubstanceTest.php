<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;

/*
 | PHASE 6 (C.5) unit gate — the AllergySubstance value object (12-clinical-domain.md
 | §30, §32, §75; ADR-141 D-E). Name required non-blank, code and code_system pairing,
 | no terminology engine.
 */

test('of() with name only is uncoded', function () {
    $s = AllergySubstance::of(name: 'Peanuts');

    expect($s->name)->toBe('Peanuts')
        ->and($s->code)->toBeNull()
        ->and($s->codeSystem)->toBeNull()
        ->and($s->isCoded())->toBeFalse();
});

test('of() with name + code + code_system is coded', function () {
    $s = AllergySubstance::of(name: 'Penicillin', code: '764146007', codeSystem: 'SNOMED-CT');

    expect($s->name)->toBe('Penicillin')
        ->and($s->code)->toBe('764146007')
        ->and($s->codeSystem)->toBe('SNOMED-CT')
        ->and($s->isCoded())->toBeTrue();
});

test('blank or whitespace-only name is rejected (ADR-141 D-E)', function () {
    expect(fn () => AllergySubstance::of(name: '   '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => AllergySubstance::of(name: ''))->toThrow(InvalidArgumentException::class);
});

test('a code without a code_system is rejected (ADR-141 D-E)', function () {
    expect(fn () => AllergySubstance::of(name: 'Aspirin', code: '1191'))->toThrow(InvalidArgumentException::class);
});

test('a code_system without a code is rejected (ADR-141 D-E)', function () {
    expect(fn () => AllergySubstance::of(name: 'Aspirin', codeSystem: 'RxNorm'))->toThrow(InvalidArgumentException::class);
});

test('blank code / code_system normalises to null', function () {
    $s = AllergySubstance::of(name: 'Aspirin', code: '   ', codeSystem: '');
    expect($s->isCoded())->toBeFalse()
        ->and($s->code)->toBeNull()
        ->and($s->codeSystem)->toBeNull();
});

test('values are trimmed', function () {
    $s = AllergySubstance::of(name: '  Penicillin  ', code: ' 764146007 ', codeSystem: ' SNOMED-CT ');
    expect($s->name)->toBe('Penicillin')
        ->and($s->code)->toBe('764146007')
        ->and($s->codeSystem)->toBe('SNOMED-CT');
});

test('an over-long name (> 500 chars) is rejected', function () {
    expect(fn () => AllergySubstance::of(name: str_repeat('a', 501)))->toThrow(InvalidArgumentException::class);
});

test('an over-long code / code_system (> 100 chars) is rejected', function (array $args) {
    expect(fn () => AllergySubstance::of(...$args))->toThrow(InvalidArgumentException::class);
})->with([
    'long code' => [['name' => 'Drug', 'code' => str_repeat('x', 101), 'codeSystem' => 'SYS']],
    'long system' => [['name' => 'Drug', 'code' => '123', 'codeSystem' => str_repeat('y', 101)]],
]);

test('an open non-standard code system string is accepted (no enum / catalogue)', function () {
    $s = AllergySubstance::of(name: 'Local Allergen', code: 'LOC-01', codeSystem: 'local-allergens');
    expect($s->codeSystem)->toBe('local-allergens');
});

test('the value object is final readonly', function () {
    $r = new ReflectionClass(AllergySubstance::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
