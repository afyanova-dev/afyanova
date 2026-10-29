<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record or revise what happened (or will happen) to the patient at the end of
 * this attendance — the disposition — on an `ACTIVE` encounter
 * (11-encounter-domain.md §12, §76, §77, §79, §101; ADR-121, ADR-130 A). Status
 * is unchanged (`ACTIVE → ACTIVE`, ADR-121); only `disposition` moves.
 *
 * Settable values (ADR-130 A, ratified): `DISCHARGED`, `REFERRED`, `LEFT`,
 * `FOLLOW_UP`, `DECEASED`, `OTHER`. `ADMITTED` is owned by `AdmitEncounter` and
 * `TRANSFERRED` by `TransferEncounter`, each set alongside the type / status
 * change that makes it coherent — this command rejects both
 * (`IllegalEncounterTransition`, 409). "Refer out" = `ChangeEncounterDisposition(REFERRED)`
 * then `CompleteEncounter` (a later increment); the inpatient discharge path is
 * `AdmitEncounter` then `ChangeEncounterDisposition(DISCHARGED)` then
 * `CompleteEncounter` (§79) — Bed & Ward releases the bed off `EncounterCompleted`,
 * not off this event (ADR-107).
 *
 * Per-subject command (`subjectId() = $encounterId`): the bus loads the
 * encounter through the RLS-scoped repository and calls
 * `EncounterPolicy::changeDisposition($actor, $encounter)` before the handler
 * runs. `$actorId` is the authenticated principal (set at the HTTP boundary in
 * PHASE 16). Tenant / facility come from the loaded aggregate, never the command
 * (ADR-037, Invariant 12). No `reason` / `changedAt` — the disposition value is
 * the whole payload; the change time is server `now()` on the event / audit row
 * (ADR-130 C/E — no per-transition column).
 */
final readonly class ChangeEncounterDisposition implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
        public EncounterDisposition $disposition,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('ChangeEncounterDisposition::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ChangeEncounterDisposition::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'changeDisposition';
    }

    public function subjectId(): string
    {
        return $this->encounterId; // per-subject — the bus loads this encounter (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.disposition_changed',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
        );
    }
}
