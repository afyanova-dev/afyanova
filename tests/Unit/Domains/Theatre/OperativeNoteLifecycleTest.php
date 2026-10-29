<?php

declare(strict_types=1);

use App\Domains\Theatre\Domain\Exception\IllegalOperativeNoteTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForOperativeNote;
use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Service\OperativeNoteLifecycle;

beforeEach(function () {
    $this->lifecycle = new OperativeNoteLifecycle;
});

test('assertCaseEligibleForNote allows only IN_PROGRESS, COMPLETED, ABORTED (ADR-152 D-D)', function () {
    $this->lifecycle->assertCaseEligibleForNote('case-1', SurgicalCase::STATUS_IN_PROGRESS);
    $this->lifecycle->assertCaseEligibleForNote('case-1', SurgicalCase::STATUS_COMPLETED);
    $this->lifecycle->assertCaseEligibleForNote('case-1', SurgicalCase::STATUS_ABORTED);

    foreach ([
        SurgicalCase::STATUS_REQUESTED,
        SurgicalCase::STATUS_SCHEDULED,
        SurgicalCase::STATUS_PRE_OP,
        SurgicalCase::STATUS_READY,
        SurgicalCase::STATUS_CANCELLED,
        SurgicalCase::STATUS_POSTPONED,
        SurgicalCase::STATUS_NO_SHOW,
    ] as $status) {
        expect(fn () => $this->lifecycle->assertCaseEligibleForNote('case-1', $status))
            ->toThrow(SurgicalCaseNotEligibleForOperativeNote::class);
    }
});

test('assertCanUpdateContent allows DRAFT and REVIEWED, rejects SIGNED and AMENDED', function () {
    $this->lifecycle->assertCanUpdateContent(OperativeNote::STATUS_DRAFT);
    $this->lifecycle->assertCanUpdateContent(OperativeNote::STATUS_REVIEWED);

    expect(fn () => $this->lifecycle->assertCanUpdateContent(OperativeNote::STATUS_SIGNED))
        ->toThrow(IllegalOperativeNoteTransition::class);
    expect(fn () => $this->lifecycle->assertCanUpdateContent(OperativeNote::STATUS_AMENDED))
        ->toThrow(IllegalOperativeNoteTransition::class);
});

test('assertCanReview allows only DRAFT', function () {
    $this->lifecycle->assertCanReview(OperativeNote::STATUS_DRAFT);

    foreach ([OperativeNote::STATUS_REVIEWED, OperativeNote::STATUS_SIGNED, OperativeNote::STATUS_AMENDED] as $status) {
        expect(fn () => $this->lifecycle->assertCanReview($status))
            ->toThrow(IllegalOperativeNoteTransition::class);
    }
});

test('assertCanSign allows DRAFT or REVIEWED (REVIEWED is optional — ADR-152 D-D)', function () {
    $this->lifecycle->assertCanSign(OperativeNote::STATUS_DRAFT);
    $this->lifecycle->assertCanSign(OperativeNote::STATUS_REVIEWED);

    expect(fn () => $this->lifecycle->assertCanSign(OperativeNote::STATUS_SIGNED))
        ->toThrow(IllegalOperativeNoteTransition::class);
    expect(fn () => $this->lifecycle->assertCanSign(OperativeNote::STATUS_AMENDED))
        ->toThrow(IllegalOperativeNoteTransition::class);
});

test('assertCanAmend allows only SIGNED or AMENDED', function () {
    $this->lifecycle->assertCanAmend(OperativeNote::STATUS_SIGNED);
    $this->lifecycle->assertCanAmend(OperativeNote::STATUS_AMENDED);

    foreach ([OperativeNote::STATUS_DRAFT, OperativeNote::STATUS_REVIEWED] as $status) {
        expect(fn () => $this->lifecycle->assertCanAmend($status))
            ->toThrow(IllegalOperativeNoteTransition::class);
    }
});
