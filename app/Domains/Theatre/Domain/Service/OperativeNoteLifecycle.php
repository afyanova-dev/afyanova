<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Service;

use App\Domains\Theatre\Domain\Exception\IllegalOperativeNoteTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForOperativeNote;
use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Domains\Theatre\Domain\Model\SurgicalCase;

/**
 * OperativeNote lifecycle rules (17-theatre-domain.md §79–§81, §303;
 * ADR-152 D-D). Pure domain logic, no I/O. The SurgicalCaseLifecycle /
 * ReferralLifecycle precedent.
 */
final class OperativeNoteLifecycle
{
    /** @var list<string> A note may be created only for a case that reached surgery. */
    private const CASE_ELIGIBLE_STATUSES = [
        SurgicalCase::STATUS_IN_PROGRESS,
        SurgicalCase::STATUS_COMPLETED,
        SurgicalCase::STATUS_ABORTED,
    ];

    public function assertCaseEligibleForNote(string $caseId, string $caseStatus): void
    {
        if (! in_array($caseStatus, self::CASE_ELIGIBLE_STATUSES, true)) {
            throw SurgicalCaseNotEligibleForOperativeNote::forStatus($caseId, $caseStatus);
        }
    }

    public function assertCanUpdateContent(string $status): void
    {
        if (! in_array($status, OperativeNote::CONTENT_MUTABLE_STATUSES, true)) {
            throw IllegalOperativeNoteTransition::fromStatus('UpdateOperativeNoteContent', $status);
        }
    }

    public function assertCanReview(string $status): void
    {
        if ($status !== OperativeNote::STATUS_DRAFT) {
            throw IllegalOperativeNoteTransition::fromStatus('ReviewOperativeNote', $status);
        }
    }

    public function assertCanSign(string $status): void
    {
        if (! in_array($status, [OperativeNote::STATUS_DRAFT, OperativeNote::STATUS_REVIEWED], true)) {
            throw IllegalOperativeNoteTransition::fromStatus('SignOperativeNote', $status);
        }
    }

    public function assertCanAmend(string $status): void
    {
        if (! in_array($status, OperativeNote::AMENDABLE_STATUSES, true)) {
            throw IllegalOperativeNoteTransition::fromStatus('AmendOperativeNote', $status);
        }
    }
}
