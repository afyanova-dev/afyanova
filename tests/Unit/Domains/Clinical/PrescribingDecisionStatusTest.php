<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\PrescribingDecisionStatus;

test('PrescribingDecisionStatus accepts ACTIVE, SUPERSEDED, DISCONTINUED', function (string $status) {
    expect(PrescribingDecisionStatus::isValid($status))->toBeTrue()
        ->and(PrescribingDecisionStatus::assert($status))->toBe($status);
})->with([
    'ACTIVE',
    'SUPERSEDED',
    'DISCONTINUED',
]);

test('PrescribingDecisionStatus rejects unknown status', function () {
    expect(PrescribingDecisionStatus::isValid('COMPLETED'))->toBeFalse();

    PrescribingDecisionStatus::assert('COMPLETED');
})->throws(InvalidArgumentException::class, 'Invalid PrescribingDecisionStatus');
