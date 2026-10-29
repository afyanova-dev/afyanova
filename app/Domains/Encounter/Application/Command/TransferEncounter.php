<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Transfer an encounter out to another facility — `ACTIVE → TRANSFERRED`
 * (11-encounter-domain.md §19, §25, §69, §90, §91, §101, §169; ADR-121, ADR-130).
 * The patient is leaving this facility; this attendance ends with terminal
 * status `TRANSFERRED` and disposition `TRANSFERRED` (ADR-130 A). The receiving
 * facility opens its OWN separate encounter through its own `CreateEncounter`
 * check-in (§8, §91) — this command neither creates the receiving encounter nor
 * writes a `TRANSFERRED_FROM` link row (ADR-130 Deferred; cross-encounter
 * linking is Care Coordination's, a future ADR).
 *
 * `TRANSFERRED` is terminal in PHASE 5 — there is no `ReopenEncounter` path
 * (`assertCanReopen` permits `COMPLETED` only). Transferring an already-terminal
 * encounter is rejected (not idempotent), consistent with every other lifecycle
 * command.
 *
 * Per-subject command (`subjectId() = $encounterId`): the bus loads the
 * encounter through the RLS-scoped repository and calls
 * `EncounterPolicy::transfer($actor, $encounter)` before the handler runs.
 * `$actorId` is the authenticated principal (set at the HTTP boundary in
 * PHASE 16). Tenant / facility come from the loaded aggregate, never the command
 * (ADR-037, Invariant 12). No `destinationFacilityId` / `reason` / `disposition`
 * / `transferredAt` / `idempotencyKey` — the outcome is structurally fixed and
 * the transfer time is server `now()` on the event / audit row (ADR-130 C/E).
 */
final readonly class TransferEncounter implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('TransferEncounter::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('TransferEncounter::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'transfer';
    }

    public function subjectId(): string
    {
        return $this->encounterId; // per-subject — the bus loads this encounter (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.transferred',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
        );
    }
}
