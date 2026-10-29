<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Service;

use App\Domains\Theatre\Domain\Exception\IllegalAnaesthesiaRecordTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForAnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Domains\Theatre\Domain\Model\SurgicalCase;

/**
 * AnaesthesiaRecord lifecycle rules (17-theatre-domain.md §36–§40; ADR-155
 * D-C). Pure domain logic, no I/O. The OperativeNoteLifecycle precedent —
 * DRAFT → SIGNED → AMENDED, no REVIEWED.
 */
final class AnaesthesiaRecordLifecycle
{
    /** @var list<string> A record may be created only for a case that reached surgery. */
    private const CASE_ELIGIBLE_STATUSES = [
        SurgicalCase::STATUS_IN_PROGRESS,
        SurgicalCase::STATUS_COMPLETED,
        SurgicalCase::STATUS_ABORTED,
    ];

    public function assertCaseEligibleForRecord(string $caseId, string $caseStatus): void
    {
        if (! in_array($caseStatus, self::CASE_ELIGIBLE_STATUSES, true)) {
            throw SurgicalCaseNotEligibleForAnaesthesiaRecord::forStatus($caseId, $caseStatus);
        }
    }

    public function assertCanUpdateContent(string $status): void
    {
        if (! in_array($status, AnaesthesiaRecord::CONTENT_MUTABLE_STATUSES, true)) {
            throw IllegalAnaesthesiaRecordTransition::fromStatus('UpdateAnaesthesiaContent', $status);
        }
    }

    public function assertCanSign(string $status): void
    {
        if ($status !== AnaesthesiaRecord::STATUS_DRAFT) {
            throw IllegalAnaesthesiaRecordTransition::fromStatus('SignAnaesthesiaRecord', $status);
        }
    }

    public function assertCanAmend(string $status): void
    {
        if (! in_array($status, AnaesthesiaRecord::AMENDABLE_STATUSES, true)) {
            throw IllegalAnaesthesiaRecordTransition::fromStatus('AmendAnaesthesiaRecord', $status);
        }
    }
}
