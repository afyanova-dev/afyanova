<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordPlannedProcedure` was attempted for a surgical case that is not in a
 * state where procedures can be planned or performed (17-theatre-domain.md
 * §10; ADR-156 D-C). Maps to HTTP 409.
 */
final class SurgicalCaseNotEligibleForProcedure extends DomainException
{
    public static function forStatus(string $caseId, string $status): self
    {
        return new self(sprintf(
            'Surgical case [%s] is [%s] — a procedure requires SCHEDULED, PRE_OP, READY, IN_PROGRESS, COMPLETED or ABORTED.',
            $caseId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'surgical_case_not_eligible_for_procedure';
    }
}
