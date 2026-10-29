<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateOperativeNote` was attempted for a surgical case that has not
 * reached surgery (17-theatre-domain.md §77; ADR-152 D-D). An operative note
 * documents an operation that happened or was attempted — the case must be
 * IN_PROGRESS, COMPLETED or ABORTED. Maps to HTTP 409.
 */
final class SurgicalCaseNotEligibleForOperativeNote extends DomainException
{
    public static function forStatus(string $caseId, string $status): self
    {
        return new self(sprintf(
            'Surgical case [%s] is [%s] — an operative note requires IN_PROGRESS, COMPLETED or ABORTED.',
            $caseId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'surgical_case_not_eligible_for_operative_note';
    }
}
