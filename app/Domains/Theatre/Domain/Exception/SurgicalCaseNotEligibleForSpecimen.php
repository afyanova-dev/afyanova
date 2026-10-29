<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordSurgicalSpecimen` named a case that is not in a state where a specimen
 * can be recorded (17-theatre-domain.md §72; ADR-164 D-B). A specimen is
 * produced during or after the operation — case ∈ {IN_PROGRESS, COMPLETED,
 * ABORTED}. Maps to HTTP 409.
 */
final class SurgicalCaseNotEligibleForSpecimen extends DomainException
{
    public static function forStatus(string $caseId, string $caseStatus): self
    {
        return new self(sprintf(
            'Surgical case [%s] is [%s] — a specimen can only be recorded for an IN_PROGRESS, COMPLETED or ABORTED case.',
            $caseId,
            $caseStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'surgical_case_not_eligible_for_specimen';
    }
}
