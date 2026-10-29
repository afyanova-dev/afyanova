<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Service;

use App\Domains\Theatre\Domain\Exception\IllegalRecoveryHandoffTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForRecoveryHandoff;
use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Domains\Theatre\Domain\Model\SurgicalCase;

/**
 * RecoveryHandoff lifecycle rules (17-theatre-domain.md §90; ADR-156 D-D).
 * Pure domain logic, no I/O.
 */
final class RecoveryHandoffLifecycle
{
    /** @var list<string> A handoff documents a case that reached (or attempted) surgery. */
    private const CASE_ELIGIBLE_STATUSES = [
        SurgicalCase::STATUS_IN_PROGRESS,
        SurgicalCase::STATUS_COMPLETED,
        SurgicalCase::STATUS_ABORTED,
    ];

    public function assertCaseEligibleForHandoff(string $caseId, string $caseStatus): void
    {
        if (! in_array($caseStatus, self::CASE_ELIGIBLE_STATUSES, true)) {
            throw SurgicalCaseNotEligibleForRecoveryHandoff::forStatus($caseId, $caseStatus);
        }
    }

    public function assertCanAcknowledge(string $status): void
    {
        if ($status !== RecoveryHandoff::STATUS_INITIATED) {
            throw IllegalRecoveryHandoffTransition::fromStatus('AcknowledgeRecoveryHandoff', $status);
        }
    }
}
