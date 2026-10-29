<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordAnaesthesia` was attempted for a surgical case that has not reached
 * surgery (17-theatre-domain.md §36; ADR-155 D-C — the T.2 eligibility gate).
 * The case must be IN_PROGRESS, COMPLETED or ABORTED. Maps to HTTP 409.
 */
final class SurgicalCaseNotEligibleForAnaesthesiaRecord extends DomainException
{
    public static function forStatus(string $caseId, string $status): self
    {
        return new self(sprintf(
            'Surgical case [%s] is [%s] — an anaesthesia record requires IN_PROGRESS, COMPLETED or ABORTED.',
            $caseId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'surgical_case_not_eligible_for_anaesthesia_record';
    }
}
