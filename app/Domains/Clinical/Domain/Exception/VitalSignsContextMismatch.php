<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordVitalSigns` named a `patientId` that is not the patient of the
 * `encounterId` it also named (12-clinical-domain.md §6–§8, §20; ADR-135 /
 * ADR-138 — a clinical record is linked to exactly one patient AND one
 * encounter, and they must be consistent).
 *
 * The encounter is authoritative for the patient link
 * (`EncounterApi::getEncounterContext` returns the real `patientId`); a command
 * whose two references disagree is rejected before any row is written. Maps to
 * HTTP 409 (IC §15). (Clinical defines its own per-record mismatch exceptions —
 * this is the vitals analogue of `ClinicalNoteContextMismatch`.)
 */
final class VitalSignsContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'RecordVitalSigns named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'vital_signs_context_mismatch';
    }
}
