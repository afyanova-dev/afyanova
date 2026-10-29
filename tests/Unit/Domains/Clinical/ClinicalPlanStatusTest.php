<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;

test('ClinicalPlanStatus accepts ACTIVE, COMPLETED, DISCONTINUED', function (string $status) {
    expect(ClinicalPlanStatus::isValid($status))->toBeTrue()
        ->and(ClinicalPlanStatus::assert($status))->toBe($status);
})->with([
    'ACTIVE',
    'COMPLETED',
    'DISCONTINUED',
]);

test('ClinicalPlanStatus rejects unknown status', function () {
    expect(ClinicalPlanStatus::isValid('RESOLVED'))->toBeFalse();

    ClinicalPlanStatus::assert('RESOLVED');
})->throws(InvalidArgumentException::class, 'Invalid ClinicalPlanStatus');
