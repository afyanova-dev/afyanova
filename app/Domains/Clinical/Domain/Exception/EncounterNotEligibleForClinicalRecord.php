<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateClinicalNote` was attempted against an encounter whose status does not
 * permit clinical documentation to ORIGINATE (12-clinical-domain.md §8, §48,
 * Invariant 19; ADR-135 — the encounter-state gate).
 *
 * A clinical record is created only when the encounter status is one of:
 *
 *   - `ARRIVED`   — the patient is present; pre-consultation documentation is
 *                   legitimate;
 *   - `ACTIVE`    — care is in progress;
 *   - `COMPLETED` — late / backdated documentation of care that occurred during
 *                   the attendance is legitimate (`12` §97).
 *
 * Rejected for `PLANNED` (no attendance yet), `CANCELLED` / `ABANDONED` /
 * `LEFT_WITHOUT_BEING_SEEN` (void — no care), and `TRANSFERRED` (the clinical
 * record continues at the receiving facility's own encounter).
 *
 * ONLY origination is gated: once a note has legitimately originated, signing,
 * cancellation, amendment and reads are independent of the encounter's later
 * state (Invariant 19; ADR-135). Maps to HTTP 409 (IC §15).
 */
final class EncounterNotEligibleForClinicalRecord extends DomainException
{
    public static function status(string $encounterId, string $status): self
    {
        return new self(sprintf(
            'Encounter [%s] is %s — a clinical record cannot be originated against it. '.
            'Origination requires ARRIVED, ACTIVE or COMPLETED.',
            $encounterId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_not_eligible_for_clinical_record';
    }
}
