<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `InitiateRecoveryHandoff` was attempted for a case that has not reached
 * surgery (17-theatre-domain.md §90; ADR-156 D-D). Maps to HTTP 409.
 */
final class SurgicalCaseNotEligibleForRecoveryHandoff extends DomainException
{
    public static function forStatus(string $caseId, string $status): self
    {
        return new self(sprintf(
            'Surgical case [%s] is [%s] — a recovery handoff requires IN_PROGRESS, COMPLETED or ABORTED.',
            $caseId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'surgical_case_not_eligible_for_recovery_handoff';
    }
}
