<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set / change the coarse care-context location — the §177 "Core Location" —
 * of an `ARRIVED` or `ACTIVE` encounter (11-encounter-domain.md §36, §37, §38,
 * §131, §163; ADR-121, ADR-130 B). Status is unchanged (ADR-121). This is
 * care-context only: it does NOT touch bed occupancy (Bed & Ward Management,
 * ADR-107), room-level operational location / movement history (Queueing &
 * Patient Flow, doc 10 §6), queue assignment (§165), the care team (§162) or
 * status.
 *
 * Patch semantics (ADR-130 B "and/or"; ratified D-2): a non-null `$departmentId`
 * sets the department, a non-null `$unitId` sets the unit, `null` leaves that
 * field unchanged. At least one must be non-null. There is no clear-to-null
 * operation in PHASE 5. PHASE 5 stores the ids WITHOUT structural validation
 * that they belong to the encounter's facility — there is no `OrganizationApi`
 * method for it and they are trusted server-side inputs from an authorized
 * actor (ADR-130 B); a department-in-facility cross-check is an additive future
 * refinement.
 *
 * Per-subject command (`subjectId() = $encounterId`): the bus loads the
 * encounter through the RLS-scoped repository and calls
 * `EncounterPolicy::assignLocation($actor, $encounter)` before the handler
 * runs. `$actorId` is the authenticated principal (set at the HTTP boundary in
 * PHASE 16). Tenant / facility come from the loaded aggregate, never the command
 * (ADR-037, Invariant 12). No `reason` / `changedAt` — the change time is server
 * `now()` on the event / audit row (ADR-130 C/E — no per-transition column).
 */
final readonly class AssignEncounterLocation implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
        public ?string $departmentId,
        public ?string $unitId,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('AssignEncounterLocation::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AssignEncounterLocation::$actorId must be a UUID (IC §3).');
        }

        if ($departmentId !== null && ! Str::isUuid($departmentId)) {
            throw new InvalidArgumentException('AssignEncounterLocation::$departmentId must be a UUID or null (IC §3).');
        }

        if ($unitId !== null && ! Str::isUuid($unitId)) {
            throw new InvalidArgumentException('AssignEncounterLocation::$unitId must be a UUID or null (IC §3).');
        }

        if ($departmentId === null && $unitId === null) {
            throw new InvalidArgumentException('AssignEncounterLocation requires at least one of $departmentId / $unitId.');
        }
    }

    public function policyAbility(): string
    {
        return 'assignLocation';
    }

    public function subjectId(): string
    {
        return $this->encounterId; // per-subject — the bus loads this encounter (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.location_changed',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
        );
    }
}
