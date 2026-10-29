<?php

declare(strict_types=1);

use App\Domains\Theatre\Domain\Exception\IllegalAnaesthesiaRecordTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForAnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Service\AnaesthesiaRecordLifecycle;

beforeEach(function () {
    $this->lifecycle = new AnaesthesiaRecordLifecycle;
});

test('assertCaseEligibleForRecord allows only IN_PROGRESS, COMPLETED, ABORTED', function () {
    $this->lifecycle->assertCaseEligibleForRecord('c', SurgicalCase::STATUS_IN_PROGRESS);
    $this->lifecycle->assertCaseEligibleForRecord('c', SurgicalCase::STATUS_COMPLETED);
    $this->lifecycle->assertCaseEligibleForRecord('c', SurgicalCase::STATUS_ABORTED);

    foreach ([SurgicalCase::STATUS_REQUESTED, SurgicalCase::STATUS_SCHEDULED, SurgicalCase::STATUS_READY, SurgicalCase::STATUS_CANCELLED] as $status) {
        expect(fn () => $this->lifecycle->assertCaseEligibleForRecord('c', $status))
            ->toThrow(SurgicalCaseNotEligibleForAnaesthesiaRecord::class);
    }
});

test('assertCanUpdateContent allows only DRAFT', function () {
    $this->lifecycle->assertCanUpdateContent(AnaesthesiaRecord::STATUS_DRAFT);

    foreach ([AnaesthesiaRecord::STATUS_SIGNED, AnaesthesiaRecord::STATUS_AMENDED] as $status) {
        expect(fn () => $this->lifecycle->assertCanUpdateContent($status))
            ->toThrow(IllegalAnaesthesiaRecordTransition::class);
    }
});

test('assertCanSign allows only DRAFT', function () {
    $this->lifecycle->assertCanSign(AnaesthesiaRecord::STATUS_DRAFT);

    foreach ([AnaesthesiaRecord::STATUS_SIGNED, AnaesthesiaRecord::STATUS_AMENDED] as $status) {
        expect(fn () => $this->lifecycle->assertCanSign($status))
            ->toThrow(IllegalAnaesthesiaRecordTransition::class);
    }
});

test('assertCanAmend allows only SIGNED or AMENDED', function () {
    $this->lifecycle->assertCanAmend(AnaesthesiaRecord::STATUS_SIGNED);
    $this->lifecycle->assertCanAmend(AnaesthesiaRecord::STATUS_AMENDED);

    expect(fn () => $this->lifecycle->assertCanAmend(AnaesthesiaRecord::STATUS_DRAFT))
        ->toThrow(IllegalAnaesthesiaRecordTransition::class);
});
