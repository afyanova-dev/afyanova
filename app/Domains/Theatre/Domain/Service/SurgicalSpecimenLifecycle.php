<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Service;

use App\Domains\Theatre\Domain\Exception\IllegalSurgicalSpecimenTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForSpecimen;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;

/**
 * SurgicalSpecimen lifecycle rules (17-theatre-domain.md §72–§74, §318;
 * ADR-164 D-B). Pure domain logic, no I/O.
 *
 * Chain of custody (§74): COLLECTED → LABELED → HANDED_OVER → RECEIVED.
 * `LabelSurgicalSpecimen` is optional (hand-off may go straight from
 * COLLECTED). A duplicate hand-off (already HANDED_OVER / RECEIVED) is
 * rejected (§318).
 */
final class SurgicalSpecimenLifecycle
{
    /** A specimen is produced during or after the operation (§72). */
    private const CASE_ELIGIBLE_STATUSES = [
        SurgicalCase::STATUS_IN_PROGRESS,
        SurgicalCase::STATUS_COMPLETED,
        SurgicalCase::STATUS_ABORTED,
    ];

    public function assertCaseEligibleForSpecimen(string $caseId, string $caseStatus): void
    {
        if (! in_array($caseStatus, self::CASE_ELIGIBLE_STATUSES, true)) {
            throw SurgicalCaseNotEligibleForSpecimen::forStatus($caseId, $caseStatus);
        }
    }

    public function assertCanLabel(string $status): void
    {
        if ($status !== SurgicalSpecimen::STATUS_COLLECTED) {
            throw IllegalSurgicalSpecimenTransition::fromStatus('LabelSurgicalSpecimen', $status);
        }
    }

    public function assertCanHandOff(string $status): void
    {
        if (! in_array($status, [SurgicalSpecimen::STATUS_COLLECTED, SurgicalSpecimen::STATUS_LABELED], true)) {
            throw IllegalSurgicalSpecimenTransition::fromStatus('HandOffSurgicalSpecimen', $status);
        }
    }

    public function assertCanMarkReceived(string $status): void
    {
        if ($status !== SurgicalSpecimen::STATUS_HANDED_OVER) {
            throw IllegalSurgicalSpecimenTransition::fromStatus('MarkSurgicalSpecimenReceived', $status);
        }
    }
}
