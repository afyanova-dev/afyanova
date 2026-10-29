<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reopen a completed encounter — `COMPLETED → ACTIVE` (11-encounter-domain.md
 * §19, §68, §88, §101; ADR-121, ADR-130 C, Rulings E8-R1..E8-R8).
 *
 * Privileged correction primitive gated by `encounter.encounters.reopen`
 * (ADR-130). Legal only when the encounter's current status is `COMPLETED`.
 *
 * Immutability & timestamp semantics (ADR-130 C, Ruling E8-R1):
 *   - `completed_at` is preserved unchanged (it is set once on first completion
 *     and never modified, preserving Invariant 16).
 *   - `disposition`, `encounter_type`, `admitted_at`, `arrived_at`, `started_at`,
 *     and coarse care-context location (`department_id`, `unit_id`) are preserved.
 *
 * Uniqueness semantics (ADR-121, Ruling E8-R4):
 *   - `COMPLETED → ACTIVE` re-enters the `enc_one_active_per_patient_facility`
 *     partial-index predicate. Uniqueness collision is translated by the handler
 *     into `ActiveEncounterExists`.
 *
 * Per-subject command (`subjectId() = $encounterId`): the bus loads the
 * encounter through the RLS-scoped repository and calls
 * `EncounterPolicy::reopen($actor, $encounter)` before the handler runs.
 * `$actorId` is the authenticated principal (set at the HTTP boundary in
 * PHASE 16). Tenant / facility come from the loaded aggregate, never the command
 * (ADR-037, Invariant 12). No reason/evidence/timestamp/disposition parameters.
 */
final readonly class ReopenEncounter implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('ReopenEncounter::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReopenEncounter::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'reopen';
    }

    public function subjectId(): string
    {
        return $this->encounterId; // per-subject — the bus loads this encounter (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.reopened',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
        );
    }
}
