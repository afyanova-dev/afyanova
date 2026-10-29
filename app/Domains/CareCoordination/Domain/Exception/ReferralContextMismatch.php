<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateReferral` named a `patientId` that is not the patient of the
 * `sourceEncounterId` it also named (ADR-151 D-G — the
 * `ClinicalNoteContextMismatch` precedent). The encounter is authoritative
 * for the patient link. Maps to HTTP 409.
 */
final class ReferralContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'CreateReferral named patient [%s] but source encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'referral_context_mismatch';
    }
}
