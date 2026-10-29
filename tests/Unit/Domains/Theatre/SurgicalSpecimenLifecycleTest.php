<?php

declare(strict_types=1);

use App\Domains\Theatre\Domain\Exception\IllegalSurgicalSpecimenTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForSpecimen;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Domains\Theatre\Domain\Service\SurgicalSpecimenLifecycle;

beforeEach(function () {
    $this->lifecycle = new SurgicalSpecimenLifecycle;
});

test('assertCaseEligibleForSpecimen allows IN_PROGRESS / COMPLETED / ABORTED only', function () {
    foreach ([SurgicalCase::STATUS_IN_PROGRESS, SurgicalCase::STATUS_COMPLETED, SurgicalCase::STATUS_ABORTED] as $status) {
        $this->lifecycle->assertCaseEligibleForSpecimen('c', $status);
    }

    foreach ([SurgicalCase::STATUS_SCHEDULED, SurgicalCase::STATUS_PRE_OP, SurgicalCase::STATUS_READY, SurgicalCase::STATUS_CLOSED, SurgicalCase::STATUS_CANCELLED] as $status) {
        expect(fn () => $this->lifecycle->assertCaseEligibleForSpecimen('c', $status))
            ->toThrow(SurgicalCaseNotEligibleForSpecimen::class);
    }
});

test('assertCanHandOff allows COLLECTED and LABELED only — a duplicate hand-off is rejected', function () {
    $this->lifecycle->assertCanHandOff(SurgicalSpecimen::STATUS_COLLECTED);
    $this->lifecycle->assertCanHandOff(SurgicalSpecimen::STATUS_LABELED);

    foreach ([SurgicalSpecimen::STATUS_HANDED_OVER, SurgicalSpecimen::STATUS_RECEIVED] as $status) {
        expect(fn () => $this->lifecycle->assertCanHandOff($status))->toThrow(IllegalSurgicalSpecimenTransition::class);
    }
});

test('assertCanLabel allows COLLECTED only; assertCanMarkReceived allows HANDED_OVER only', function () {
    $this->lifecycle->assertCanLabel(SurgicalSpecimen::STATUS_COLLECTED);
    $this->lifecycle->assertCanMarkReceived(SurgicalSpecimen::STATUS_HANDED_OVER);

    expect(fn () => $this->lifecycle->assertCanLabel(SurgicalSpecimen::STATUS_LABELED))->toThrow(IllegalSurgicalSpecimenTransition::class);
    expect(fn () => $this->lifecycle->assertCanMarkReceived(SurgicalSpecimen::STATUS_LABELED))->toThrow(IllegalSurgicalSpecimenTransition::class);
});
