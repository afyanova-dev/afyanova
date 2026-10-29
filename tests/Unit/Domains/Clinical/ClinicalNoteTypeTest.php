<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\ClinicalNoteType;

/*
 | PHASE 6 (C.1) unit gate — the ClinicalNoteType value object
 | (12-clinical-domain.md §10; ADR-134). An OPEN coded string with a SHAPE
 | check only — no enumerated cases, no behaviour.
 */

test('it accepts any non-blank token and trims it', function () {
    expect(ClinicalNoteType::fromString('progress_note')->value)->toBe('progress_note')
        ->and(ClinicalNoteType::fromString('  Consultation Note  ')->value)->toBe('Consultation Note')
        ->and(ClinicalNoteType::fromString('discharge-summary')->value)->toBe('discharge-summary');
});

test('it rejects a blank / whitespace-only value', function (string $value) {
    expect(fn () => ClinicalNoteType::fromString($value))->toThrow(InvalidArgumentException::class);
})->with(['', '   ', "\t\n"]);

test('it rejects a value longer than 100 characters', function () {
    expect(fn () => ClinicalNoteType::fromString(str_repeat('a', 101)))->toThrow(InvalidArgumentException::class);
    expect(ClinicalNoteType::fromString(str_repeat('a', 100))->value)->toHaveLength(100);
});

test('equals() compares the underlying value', function () {
    expect(ClinicalNoteType::fromString('progress_note')->equals(ClinicalNoteType::fromString('progress_note')))->toBeTrue()
        ->and(ClinicalNoteType::fromString('progress_note')->equals(ClinicalNoteType::fromString('nursing_note')))->toBeFalse();
});

test('it is a final readonly value object with no enumerated cases', function () {
    $reflection = new ReflectionClass(ClinicalNoteType::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->isEnum())->toBeFalse();
});
