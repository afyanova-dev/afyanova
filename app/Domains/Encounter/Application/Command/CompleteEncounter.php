<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Close an attendance — `ACTIVE → COMPLETED` (11-encounter-domain.md §19, §22,
 * §67, §101; ADR-121, ADR-130). Legal only when the encounter is `ACTIVE` AND a
 * disposition has already been recorded (`disposition != null` — ADR-121
 * "disposition required"; ADR-130 A "CompleteEncounter … does not itself pick a
 * value"). Any non-null `EncounterDisposition` is accepted, including `ADMITTED`
 * (R-1); this command does not restrict the value and does not modify it.
 *
 * `COMPLETED` is terminal in PHASE 5 — `ReopenEncounter` (`COMPLETED → ACTIVE`)
 * is a later increment. Completing an already-`COMPLETED` encounter is rejected
 * (not idempotent), consistent with every other lifecycle command.
 *
 * Encounter completion is the encounter's own lifecycle ending; it does NOT
 * complete or notify downstream workflows (Invariant 18; §136–§139) — Billing,
 * Reporting, Care Coordination and Bed & Ward Management react to
 * `EncounterCompleted` on their own schedule.
 *
 * Per-subject command (`subjectId() = $encounterId`): the bus loads the
 * encounter through the RLS-scoped repository and calls
 * `EncounterPolicy::complete($actor, $encounter)` before the handler runs.
 * `$actorId` is the authenticated principal (set at the HTTP boundary in
 * PHASE 16). Tenant / facility come from the loaded aggregate, never the command
 * (ADR-037, Invariant 12). No `completedAt` — the completion time is server
 * `now()` (§31, §32, ADR-130 C). No `reason` / `idempotencyKey`.
 */
final readonly class CompleteEncounter implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('CompleteEncounter::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CompleteEncounter::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'complete';
    }

    public function subjectId(): string
    {
        return $this->encounterId; // per-subject — the bus loads this encounter (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.completed',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
        );
    }
}
