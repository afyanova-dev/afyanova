<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;

test('ClinicalPlanType accepts recognized constants and normalizes case', function (string $type) {
    expect(ClinicalPlanType::isValid($type))->toBeTrue()
        ->and(ClinicalPlanType::assert($type))->toBe(strtoupper($type));
})->with([
    'MEDICATION',
    'INVESTIGATION',
    'PROCEDURE',
    'FOLLOW_UP',
    'REFERRAL',
    'OBSERVATION',
    'GENERAL',
    'general',
    'Medication',
]);

test('ClinicalPlanType rejects OTHER — GENERAL is the only escape (ADR-143 D-D)', function () {
    expect(ClinicalPlanType::isValid('OTHER'))->toBeFalse();
});

test('ClinicalPlanType rejects invalid type strings', function () {
    expect(ClinicalPlanType::isValid('INVALID'))->toBeFalse();

    ClinicalPlanType::assert('UNKNOWN_PLAN_TYPE');
})->throws(InvalidArgumentException::class, 'Invalid ClinicalPlanType');
