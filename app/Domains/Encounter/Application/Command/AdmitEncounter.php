<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Admit an active OUTPATIENT / EMERGENCY encounter as an inpatient — the one
 * sanctioned encounter-type conversion, in place (11-encounter-domain.md §14,
 * §14.1, §78, §101; ADR-108, ADR-120, ADR-121, ADR-130). Status stays `ACTIVE`
 * (there is no `INPATIENT` status); `encounter_type → INPATIENT`,
 * `admitted_at` is stamped, `disposition → ADMITTED`. Still ONE encounter
 * (ADR-101) — planned / elective admission and re-admission are a NEW
 * `CreateEncounter(type: INPATIENT)` instead (§14.1).
 *
 * Per-subject command (`subjectId() = $encounterId`): the bus loads the
 * encounter through the RLS-scoped repository and calls
 * `EncounterPolicy::admit($actor, $encounter)` before the handler runs.
 * `$actorId` is the authenticated principal (set at the HTTP boundary in
 * PHASE 16). Tenant / facility come from the loaded aggregate, never the
 * command (ADR-037, Invariant 12). No `admittedAt` — the admission time is
 * server `now()` (ADR-130 C). Bed / ward assignment is Bed & Ward Management's
 * `AssignBed` (ADR-107, §14.2), not this command.
 */
final readonly class AdmitEncounter implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('AdmitEncounter::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AdmitEncounter::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'admit';
    }

    public function subjectId(): string
    {
        return $this->encounterId; // per-subject — the bus loads this encounter (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.admitted',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
        );
    }
}
