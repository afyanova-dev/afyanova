<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Service;

use App\Domains\Theatre\Domain\Exception\IllegalSurgicalProcedureTransition;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForProcedure;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\Model\SurgicalProcedure;

/**
 * SurgicalProcedure lifecycle rules (17-theatre-domain.md §10; ADR-156 D-C).
 * Pure domain logic, no I/O.
 */
final class SurgicalProcedureLifecycle
{
    /** @var list<string> A procedure may be recorded from planning through the terminal case states. */
    private const CASE_ELIGIBLE_STATUSES = [
        SurgicalCase::STATUS_SCHEDULED,
        SurgicalCase::STATUS_PRE_OP,
        SurgicalCase::STATUS_READY,
        SurgicalCase::STATUS_IN_PROGRESS,
        SurgicalCase::STATUS_COMPLETED,
        SurgicalCase::STATUS_ABORTED,
    ];

    public function assertCaseEligibleForProcedure(string $caseId, string $caseStatus): void
    {
        if (! in_array($caseStatus, self::CASE_ELIGIBLE_STATUSES, true)) {
            throw SurgicalCaseNotEligibleForProcedure::forStatus($caseId, $caseStatus);
        }
    }

    public function assertCanStart(string $status): void
    {
        if ($status !== SurgicalProcedure::STATUS_PLANNED) {
            throw IllegalSurgicalProcedureTransition::fromStatus('StartProcedure', $status);
        }
    }

    public function assertCanComplete(string $status): void
    {
        if ($status !== SurgicalProcedure::STATUS_IN_PROGRESS) {
            throw IllegalSurgicalProcedureTransition::fromStatus('CompleteProcedure', $status);
        }
    }

    public function assertCanAbort(string $status): void
    {
        if (! in_array($status, [SurgicalProcedure::STATUS_PLANNED, SurgicalProcedure::STATUS_IN_PROGRESS], true)) {
            throw IllegalSurgicalProcedureTransition::fromStatus('AbortProcedure', $status);
        }
    }
}
