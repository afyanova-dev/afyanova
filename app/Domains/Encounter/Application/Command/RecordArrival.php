<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The patient is physically present against a pre-created `PLANNED` encounter —
 * `PLANNED → ARRIVED` (11-encounter-domain.md §19, §101; ADR-121, ADR-130).
 *
 * PHASE 5 operates only on the (rare) pre-created `PLANNED` encounter — the
 * Scheduling appointment-arrival signal is a deferred PHASE 11 integration
 * point (§101; ADR-124). A walk-in is checked in by `CreateEncounter`
 * (→ `ARRIVED` directly), not this command.
 *
 * Per-subject command (`subjectId() = $encounterId`): the bus loads the
 * encounter through the RLS-scoped repository and calls
 * `EncounterPolicy::recordArrival($actor, $encounter)` before the handler runs.
 * `$actorId` is the authenticated principal (set at the HTTP boundary in
 * PHASE 16). Tenant / facility come from context + the loaded aggregate, never
 * the command (ADR-037, Invariant 12). No `arrivedAt` — the arrival time is
 * server `now()` (ADR-130 C).
 */
final readonly class RecordArrival implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('RecordArrival::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordArrival::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'recordArrival';
    }

    public function subjectId(): string
    {
        return $this->encounterId; // per-subject — the bus loads this encounter (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.arrived',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
        );
    }
}
