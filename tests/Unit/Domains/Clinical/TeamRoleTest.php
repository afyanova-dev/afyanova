<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\TeamRole;

/*
 | PHASE 6 (C.6) unit gate — the TeamRole value object (12-clinical-domain.md
 | §4.1; ADR-142 D-F). An OPEN coded string, set-validated against a documented
 | constant list; NOT a PHP enum, NOT #[TypeScript], NOT a DB CHECK.
 */

test('TeamRole constants and the documented set', function () {
    expect(TeamRole::ATTENDING)->toBe('ATTENDING')
        ->and(TeamRole::CONSULTANT)->toBe('CONSULTANT')
        ->and(TeamRole::RESIDENT)->toBe('RESIDENT')
        ->and(TeamRole::NURSE)->toBe('NURSE')
        ->and(TeamRole::ALLIED_HEALTH)->toBe('ALLIED_HEALTH')
        ->and(TeamRole::COORDINATOR)->toBe('COORDINATOR')
        ->and(TeamRole::all())->toBe([
            'ATTENDING', 'CONSULTANT', 'RESIDENT', 'NURSE', 'ALLIED_HEALTH', 'COORDINATOR',
        ]);
});

test('isValid / assert accept the documented values', function () {
    foreach (TeamRole::all() as $role) {
        expect(TeamRole::isValid($role))->toBeTrue()
            ->and(TeamRole::assert($role))->toBe($role);
    }
});

test('assert trims surrounding whitespace', function () {
    expect(TeamRole::assert('  NURSE  '))->toBe('NURSE');
});

test('an unknown role is rejected with InvalidArgumentException', function () {
    expect(TeamRole::isValid('SURGEON'))->toBeFalse()
        ->and(fn () => TeamRole::assert('SURGEON'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TeamRole::assert('attending'))->toThrow(InvalidArgumentException::class) // case-sensitive
        ->and(fn () => TeamRole::assert(''))->toThrow(InvalidArgumentException::class);
});

test('TeamRole is a final class of string constants — NOT a PHP enum (generated.ts unchanged)', function () {
    $reflection = new ReflectionClass(TeamRole::class);

    expect($reflection->isEnum())->toBeFalse()
        ->and($reflection->isFinal())->toBeTrue();
});
