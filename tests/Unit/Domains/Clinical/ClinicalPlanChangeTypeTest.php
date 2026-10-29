<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanChangeType;

test('ClinicalPlanChangeType accepts REVISION and CORRECTION', function (string $changeType) {
    expect(ClinicalPlanChangeType::isValid($changeType))->toBeTrue()
        ->and(ClinicalPlanChangeType::assert($changeType))->toBe($changeType);
})->with([
    'REVISION',
    'CORRECTION',
]);

test('ClinicalPlanChangeType rejects unknown change type', function () {
    expect(ClinicalPlanChangeType::isValid('MUTATION'))->toBeFalse();

    ClinicalPlanChangeType::assert('MUTATION');
})->throws(InvalidArgumentException::class, 'ClinicalPlanChangeType must be one of');
