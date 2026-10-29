<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Discard a clinical note created in error — `DRAFT → CANCELLED`
 * (12-clinical-domain.md §12; ADR-134).
 *
 * A PURE aggregate state transition. It:
 *   - implements `AuthorizesAgainst` ONLY — it is NOT `Auditable`
 *     (`12` §148 audits note creation / signing / amendment, NOT draft
 *     cancellation; `12` §79 / §165 omit a cancellation event);
 *   - is gated by `clinical.notes.amend` — there is NO `clinical.notes.cancel`
 *     key (ADR-133: `12` §87 lists create / update / sign / amend, not cancel);
 *   - transitions only `DRAFT → CANCELLED` (rejecting `SIGNED` and `CANCELLED`);
 *   - increments `version`;
 *   - emits NO domain event, writes NO audit row, makes NO cross-domain call,
 *     has NO encounter-state gate (once originated, the note's lifecycle is
 *     independent of the encounter — Invariant 19, ADR-135).
 *
 * The `clinical.note_created` audit row + the `ClinicalNoteCreated` event + the
 * queryable `CANCELLED` status are the record of a cancelled draft.
 *
 * Per-subject command (`subjectId() = $clinicalNoteId`): the bus loads the note
 * and calls `ClinicalNotePolicy::cancel` (which checks the `clinical.notes.amend`
 * key at the note's facility) before the handler runs. No `reason` in C.1
 * (ADR-134 does not require one — the `CancelEncounter` precedent).
 */
final readonly class CancelClinicalNote implements AuthorizesAgainst
{
    public function __construct(
        public string $clinicalNoteId,
        public string $actorId,
    ) {
        if (! Str::isUuid($clinicalNoteId)) {
            throw new InvalidArgumentException('CancelClinicalNote::$clinicalNoteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelClinicalNote::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->clinicalNoteId; // per-subject — the bus loads this note (IC §5.1)
    }
}
