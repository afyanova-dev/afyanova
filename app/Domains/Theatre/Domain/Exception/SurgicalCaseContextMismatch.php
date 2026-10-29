<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateSurgicalCase` named a `patientId` that is not the patient of the
 * `encounterId` it also named (ADR-150 D-H — the `ClinicalNoteContextMismatch`
 * precedent). The encounter is authoritative for the patient link. Maps to
 * HTTP 409.
 */
final class SurgicalCaseContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'CreateSurgicalCase named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'surgical_case_context_mismatch';
    }
}
