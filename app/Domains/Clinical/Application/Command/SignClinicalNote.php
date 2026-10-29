<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attest a clinical note — `DRAFT → SIGNED` (12-clinical-domain.md §12, §13;
 * ADR-134). The first attestation of an existing record (not origination), so
 * it is NOT encounter-state-gated — once a note has legitimately originated its
 * lifecycle is independent of the encounter's later state (Invariant 19;
 * ADR-135). Signing is not idempotent — a second `SignClinicalNote` on a
 * `SIGNED` note is a domain exception.
 *
 * Per-subject command (`subjectId() = $clinicalNoteId`): the bus loads the note
 * through the RLS-scoped repository and calls `ClinicalNotePolicy::sign` (which
 * checks `clinical.notes.sign` at the note's own facility) before the handler
 * runs. `$actorId` is the authenticated principal — the signer. Tenant /
 * facility come from the loaded aggregate, never the command (Invariant 12).
 * `signed_at` is server `now()` (§96, ADR-132) — no timestamp on the command.
 */
final readonly class SignClinicalNote implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $clinicalNoteId,
        public string $actorId,
    ) {
        if (! Str::isUuid($clinicalNoteId)) {
            throw new InvalidArgumentException('SignClinicalNote::$clinicalNoteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SignClinicalNote::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'sign';
    }

    public function subjectId(): string
    {
        return $this->clinicalNoteId; // per-subject — the bus loads this note (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.note_signed',
            actorId: $this->actorId,
            targetType: 'clinical_note',
            targetId: $this->clinicalNoteId,
        );
    }
}
