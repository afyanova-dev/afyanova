<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\AllergyChangeType;
use App\Domains\Clinical\Domain\ValueObject\AllergyStatus;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;

/*
 | PHASE 6 (C.5) unit gate — the status value objects (12-clinical-domain.md
 | §31, §75; ADR-141 D-D / D-K). Closed backend-only classes of string constants,
 | NOT PHP enums, NOT #[TypeScript].
 */

test('AllergyStatus constants and validation', function () {
    expect(AllergyStatus::ACTIVE)->toBe('ACTIVE')
        ->and(AllergyStatus::RESOLVED)->toBe('RESOLVED')
        ->and(AllergyStatus::INACTIVE)->toBe('INACTIVE')
        ->and(AllergyStatus::all())->toBe(['ACTIVE', 'RESOLVED', 'INACTIVE'])
        ->and(AllergyStatus::isValid('ACTIVE'))->toBeTrue()
        ->and(AllergyStatus::isValid('RESOLVED'))->toBeTrue()
        ->and(AllergyStatus::isValid('INACTIVE'))->toBeTrue()
        ->and(AllergyStatus::isValid('UNKNOWN'))->toBeFalse()
        ->and(AllergyStatus::assert('ACTIVE'))->toBe('ACTIVE');

    expect(fn () => AllergyStatus::assert('INVALID'))->toThrow(InvalidArgumentException::class);
});

test('AllergyVerificationStatus constants and validation', function () {
    expect(AllergyVerificationStatus::SUSPECTED)->toBe('SUSPECTED')
        ->and(AllergyVerificationStatus::CONFIRMED)->toBe('CONFIRMED')
        ->and(AllergyVerificationStatus::REFUTED)->toBe('REFUTED')
        ->and(AllergyVerificationStatus::all())->toBe(['SUSPECTED', 'CONFIRMED', 'REFUTED'])
        ->and(AllergyVerificationStatus::RECORDABLE)->toBe(['SUSPECTED', 'CONFIRMED'])
        ->and(AllergyVerificationStatus::VERIFIABLE)->toBe(['CONFIRMED', 'REFUTED'])
        ->and(AllergyVerificationStatus::isValid('SUSPECTED'))->toBeTrue()
        ->and(AllergyVerificationStatus::isValid('CONFIRMED'))->toBeTrue()
        ->and(AllergyVerificationStatus::isValid('REFUTED'))->toBeTrue()
        ->and(AllergyVerificationStatus::isValid('INVALID'))->toBeFalse()
        ->and(AllergyVerificationStatus::assert('CONFIRMED'))->toBe('CONFIRMED')
        ->and(AllergyVerificationStatus::assertRecordable('SUSPECTED'))->toBe('SUSPECTED')
        ->and(AllergyVerificationStatus::assertRecordable('CONFIRMED'))->toBe('CONFIRMED')
        ->and(AllergyVerificationStatus::assertVerifiable('CONFIRMED'))->toBe('CONFIRMED')
        ->and(AllergyVerificationStatus::assertVerifiable('REFUTED'))->toBe('REFUTED');

    // REFUTED is not recordable at origination
    expect(fn () => AllergyVerificationStatus::assertRecordable('REFUTED'))->toThrow(InvalidArgumentException::class);

    // SUSPECTED is not a verification transition target
    expect(fn () => AllergyVerificationStatus::assertVerifiable('SUSPECTED'))->toThrow(InvalidArgumentException::class);
});

test('AllergyChangeType constants and validation', function () {
    expect(AllergyChangeType::REVISION)->toBe('REVISION')
        ->and(AllergyChangeType::CORRECTION)->toBe('CORRECTION')
        ->and(AllergyChangeType::all())->toBe(['REVISION', 'CORRECTION'])
        ->and(AllergyChangeType::isValid('REVISION'))->toBeTrue()
        ->and(AllergyChangeType::isValid('CORRECTION'))->toBeTrue()
        ->and(AllergyChangeType::isValid('OTHER'))->toBeFalse()
        ->and(AllergyChangeType::assert('REVISION'))->toBe('REVISION')
        ->and(AllergyChangeType::assert('CORRECTION'))->toBe('CORRECTION');

    expect(fn () => AllergyChangeType::assert('INVALID'))->toThrow(InvalidArgumentException::class);
});

test('status value objects are final classes, not PHP enums', function () {
    foreach ([AllergyStatus::class, AllergyVerificationStatus::class, AllergyChangeType::class] as $class) {
        $r = new ReflectionClass($class);
        expect($r->isFinal())->toBeTrue()
            ->and($r->isEnum())->toBeFalse();
    }
});
