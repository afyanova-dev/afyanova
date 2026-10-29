<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Domain\Exception\FollowUpOutcomeIncomplete;
use App\Domains\CareCoordination\Domain\Exception\IllegalFollowUpTransition;
use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Domains\CareCoordination\Domain\Service\FollowUpLifecycle;

beforeEach(function () {
    $this->lifecycle = new FollowUpLifecycle;
});

test('assertCanRecordOutcome allows only PENDING', function () {
    $this->lifecycle->assertCanRecordOutcome(FollowUp::STATUS_PENDING);

    foreach ([FollowUp::STATUS_KEPT, FollowUp::STATUS_MISSED, FollowUp::STATUS_CANCELLED, FollowUp::STATUS_NOT_REQUIRED, FollowUp::STATUS_SCHEDULED] as $status) {
        expect(fn () => $this->lifecycle->assertCanRecordOutcome($status))
            ->toThrow(IllegalFollowUpTransition::class);
    }
});

test('assertOutcomeComplete requires a resulting encounter for KEPT only', function () {
    $this->lifecycle->assertOutcomeComplete(FollowUp::STATUS_KEPT, '01a00000-0000-7000-8000-000000000000');
    $this->lifecycle->assertOutcomeComplete(FollowUp::STATUS_MISSED, null);
    $this->lifecycle->assertOutcomeComplete(FollowUp::STATUS_CANCELLED, null);

    expect(fn () => $this->lifecycle->assertOutcomeComplete(FollowUp::STATUS_KEPT, null))
        ->toThrow(FollowUpOutcomeIncomplete::class);
    expect(fn () => $this->lifecycle->assertOutcomeComplete(FollowUp::STATUS_KEPT, ''))
        ->toThrow(FollowUpOutcomeIncomplete::class);
});
