<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Service;

use App\Domains\Clinical\Domain\Enum\ClinicalNoteStatus;
use App\Domains\Clinical\Domain\Exception\IllegalClinicalNoteTransition;

/**
 * The single place every clinical-note state decision passes through
 * (12-clinical-domain.md §12; ADR-134 — "every transition via a
 * ClinicalNoteLifecycle domain service", the `EncounterLifecycle` precedent).
 * Pure domain logic, no I/O.
 *
 * The C.1 transition table (ADR-134):
 *
 *   CreateClinicalNote   (new)   → DRAFT       (origination — no source-status guard)
 *   SignClinicalNote     DRAFT   → SIGNED      {@see assertCanSign}
 *   CancelClinicalNote   DRAFT   → CANCELLED   {@see assertCanCancel}
 *   AmendClinicalNote    SIGNED  → SIGNED      {@see assertCanAmend} (appends an amendment row)
 *
 * `AMENDED` is derived, never a transition target. `UpdateClinicalNote`
 * (`DRAFT → DRAFT`), `IN_REVIEW`, co-signature and `VOIDED` are deferred.
 */
final class ClinicalNoteLifecycle
{
    /**
     * `SignClinicalNote: DRAFT → SIGNED` (ADR-134). Legal only from `DRAFT`.
     * `SIGNED` is rejected (signing is not idempotent — a second attestation is
     * a bug); `CANCELLED` is rejected (a discarded draft is not signable).
     */
    public function assertCanSign(ClinicalNoteStatus $current): void
    {
        if ($current !== ClinicalNoteStatus::Draft) {
            throw IllegalClinicalNoteTransition::for('SignClinicalNote', $current);
        }
    }

    /**
     * `CancelClinicalNote: DRAFT → CANCELLED` (ADR-134). Legal only from
     * `DRAFT` — a note created in error, before signing. `SIGNED` is rejected (a
     * high-integrity record; corrections are amendments — `12` §13/§15,
     * Invariant 5); `CANCELLED` is rejected (already discarded, not idempotent).
     */
    public function assertCanCancel(ClinicalNoteStatus $current): void
    {
        if ($current !== ClinicalNoteStatus::Draft) {
            throw IllegalClinicalNoteTransition::for('CancelClinicalNote', $current);
        }
    }

    /**
     * `AmendClinicalNote: SIGNED → SIGNED` (ADR-134) — append a
     * `clin_note_amendments` row; the note body is never rewritten. Legal only
     * from `SIGNED`. `DRAFT` is rejected (edit the draft directly — not a C.1
     * command); `CANCELLED` is rejected (nothing to amend).
     */
    public function assertCanAmend(ClinicalNoteStatus $current): void
    {
        if ($current !== ClinicalNoteStatus::Signed) {
            throw IllegalClinicalNoteTransition::for('AmendClinicalNote', $current);
        }
    }
}
