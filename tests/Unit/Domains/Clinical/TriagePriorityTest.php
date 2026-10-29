<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\TriagePriority;

/*
 | PHASE 6 (C.9) unit gate — the TriagePriority value object
 | (23-queueing-and-patient-flow.md §21; ADR-105, ADR-145 D-E / D-Q). A CLOSED
 | coded set, a backend `final class` constant holder — NOT a PHP enum, NOT
 | #[TypeScript].
 */

test('ALL is exactly the queueing §21 priority set, ordered most-to-least urgent', function () {
    expect(TriagePriority::ALL)->toBe(['EMERGENCY', 'URGENT', 'HIGH', 'NORMAL', 'LOW']);
});

test('assert() accepts every permitted value and normalizes case / whitespace', function (string $input, string $expected) {
    expect(TriagePriority::assert($input))->toBe($expected);
})->with([
    ['EMERGENCY', 'EMERGENCY'],
    ['urgent', 'URGENT'],
    ['  High  ', 'HIGH'],
    ['normal', 'NORMAL'],
    ['LOW', 'LOW'],
]);

test('assert() rejects anything outside the set', function (string $bad) {
    expect(fn () => TriagePriority::assert($bad))
        ->toThrow(InvalidArgumentException::class, 'Invalid TriagePriority');
})->with(['CRITICAL', 'ESI-2', 'RED', '', '3', 'IMMEDIATE']);

test('isValid() mirrors assert() without throwing', function () {
    expect(TriagePriority::isValid('emergency'))->toBeTrue()
        ->and(TriagePriority::isValid('  low '))->toBeTrue()
        ->and(TriagePriority::isValid('CRITICAL'))->toBeFalse()
        ->and(TriagePriority::isValid(''))->toBeFalse();
});

test('it is a plain final class — not a PHP enum and not #[TypeScript] (ADR-145 D-Q)', function () {
    $reflection = new ReflectionClass(TriagePriority::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isEnum())->toBeFalse()
        ->and($reflection->getAttributes(Spatie\TypeScriptTransformer\Attributes\TypeScript::class))->toBe([]);
});
