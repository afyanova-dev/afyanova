<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Care has begun on an arrived encounter — `ARRIVED → ACTIVE`
 * (11-encounter-domain.md §19, §31, §101; ADR-121, ADR-130). `ACTIVE` is the
 * "attendance in progress" state, decoupled from clinical / lab / queue status
 * (§21); there is no separate `STARTED` status (ADR-121).
 *
 * Per-subject command (`subjectId() = $encounterId`): the bus loads the
 * encounter through the RLS-scoped repository and calls
 * `EncounterPolicy::start($actor, $encounter)` before the handler runs.
 * `$actorId` is the authenticated principal (set at the HTTP boundary in
 * PHASE 16). Tenant / facility come from context + the loaded aggregate, never
 * the command (ADR-037, Invariant 12). No `startedAt` — the start time is
 * server `now()` (§31, ADR-130 C).
 */
final readonly class StartEncounter implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('StartEncounter::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('StartEncounter::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'start';
    }

    public function subjectId(): string
    {
        return $this->encounterId; // per-subject — the bus loads this encounter (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.started',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
        );
    }
}
