<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateClinicalNote` named a `patientId` that is not the patient of the
 * `encounterId` it also named (12-clinical-domain.md §6–§8 — a clinical record
 * is linked to exactly one patient AND one encounter, and they must be
 * consistent; ADR-135 step "verify patient / encounter consistency").
 *
 * The encounter is authoritative for the patient link (`EncounterApi::
 * getEncounterContext` returns the real `patientId`); a command whose two
 * references disagree is rejected before any row is written. Maps to HTTP 409
 * (IC §15).
 */
final class ClinicalNoteContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'CreateClinicalNote named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'clinical_note_context_mismatch';
    }
}
