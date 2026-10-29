<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordAllergy` named a patient and an encounter that are not mutually
 * consistent (12-clinical-domain.md §6–§8; ADR-141 D-B — an allergy is bound to
 * exactly one patient AND one originating encounter, and the encounter is
 * authoritative for the patient link, the `ProblemContextMismatch` /
 * `DiagnosisContextMismatch` precedent). Maps to HTTP 409 (IC §15).
 *
 * An unknown / cross-tenant / out-of-scope patient or encounter surfaces as
 * `App\Shared\Domain\NotFound` from `PatientApi` / `EncounterApi`, not this
 * exception.
 */
final class AllergyContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'RecordAllergy named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'allergy_context_mismatch';
    }
}
