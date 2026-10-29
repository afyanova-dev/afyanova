<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ScheduleFollowUp` named a `patientId` that is not the patient of the
 * `sourceEncounterId` it also named (ADR-154 D-I — the
 * `ReferralContextMismatch` precedent). Maps to HTTP 409.
 */
final class FollowUpContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'ScheduleFollowUp named patient [%s] but source encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'follow_up_context_mismatch';
    }
}
