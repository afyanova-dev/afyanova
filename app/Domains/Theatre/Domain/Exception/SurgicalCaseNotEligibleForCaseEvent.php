<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordCaseEvent` was attempted for a case still in {REQUESTED, PLANNED} —
 * nothing has happened yet to time (17-theatre-domain.md §84; ADR-156 D-E).
 * Maps to HTTP 409.
 */
final class SurgicalCaseNotEligibleForCaseEvent extends DomainException
{
    public static function forStatus(string $caseId, string $status): self
    {
        return new self(sprintf(
            'Surgical case [%s] is [%s] — no intra-op timeline before the case is scheduled.',
            $caseId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'surgical_case_not_eligible_for_case_event';
    }
}
