<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Authorization;

use App\Domains\Encounter\Application\Command\AbandonEncounter;
use App\Domains\Encounter\Application\Command\AdmitEncounter;
use App\Domains\Encounter\Application\Command\AssignEncounterLocation;
use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\RecordArrival;
use App\Domains\Encounter\Application\Command\RecordEncounterRelationship;
use App\Domains\Encounter\Application\Command\ReopenEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Application\Command\TransferEncounter;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Encounter aggregate policy (IMPLEMENTATION-CONTRACTS.md §12;
 * 11-encounter-domain.md §65, §113, §114, §181; ADR-130).
 */
final class EncounterPolicy extends BasePolicy
{
    /**
     * CreateEncounter — class-level (create) check; there is no subject to load
     * yet (IC §5.1). `permits()` resolves `encounter.encounters.create` in the
     * actor's current facility context (ScopeChecker), so a no-facility /
     * out-of-scope caller is denied here — an encounter always belongs to a
     * facility (ADR-101). The command is passed as the second argument
     * (AuthorizesAgainst) but carries no per-subject rule for a create.
     */
    public function create(User $user, ?CreateEncounter $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'encounter.encounters.create');
    }

    /**
     * GetEncounter (#[ProtectedQuery], IC §6.2; ADR-130; E.12) — class-level
     * check; the QueryBus never loads a subject instance (App\Shared\Bus\
     * Middleware\AuthorizeQuery has no per-row loading path). Coarse tenant +
     * `encounter.encounters.read`. The per-encounter facility refinement (§114,
     * Invariant 24/25 — "facility selection does not grant encounter access")
     * is `GetEncounterHandler`'s, against the ENCOUNTER's own facility (R-1) —
     * it cannot be done here because the policy has no subject.
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'encounter.encounters.read');
    }

    /**
     * RecordArrival — per-subject check; the bus loaded this encounter from its
     * (RLS-scoped) repository (IC §5.1). `RecordArrival` is a routine lifecycle
     * progression, mapped to `encounter.encounters.update` (ADR-130).
     *
     * Facility scope is evaluated against the ENCOUNTER's own facility, not the
     * actor's currently-selected one: `enc_encounters` RLS is tenant-scoped, so
     * an actor could load an encounter at a facility they are not assigned to —
     * "Facility selection does not grant encounter access" (§114, Invariant 24;
     * "Organization membership does not automatically grant every facility's
     * encounter access" — Invariant 25). `effectivePermissions($user,
     * $encounter->facility_id)` is exactly "does this actor hold the key at
     * THIS encounter's facility" (ADR-130 D).
     */
    public function recordArrival(User $user, Encounter $encounter, ?RecordArrival $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.update',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * StartEncounter — per-subject check; a routine lifecycle progression
     * (`ARRIVED → ACTIVE`, §31, §66), mapped to `encounter.encounters.update`
     * (ADR-130). Same facility-scoped evaluation as the `recordArrival` method
     * above: the key is checked against the ENCOUNTER's own facility (§114,
     * Invariant 24/25; ADR-130 D).
     */
    public function start(User $user, Encounter $encounter, ?StartEncounter $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.update',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * AdmitEncounter — per-subject check; the one sanctioned encounter-type
     * conversion (ADR-108). A high-impact change (§66) gated by its own
     * dedicated key **`encounter.encounters.admit`** (ADR-120, ADR-130 —
     * distinct from `encounter.encounters.update`). Same facility-scoped
     * evaluation as the `recordArrival` / `start` methods above: the key is
     * checked against the ENCOUNTER's own facility (§114, Invariant 24/25;
     * ADR-130 D). The `INPATIENT_CARE` capability is the handler's gate, not
     * this one.
     */
    public function admit(User $user, Encounter $encounter, ?AdmitEncounter $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.admit',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * ChangeEncounterDisposition — per-subject check; recording / revising the
     * disposition is a high-impact change (§66) gated by its own dedicated key
     * **`encounter.encounters.manage_disposition`** (ADR-130 — distinct from
     * `encounter.encounters.update`). Same facility-scoped evaluation as the
     * `recordArrival` / `start` / `admit` methods above: the key is checked
     * against the ENCOUNTER's own facility (§114, Invariant 24/25; ADR-130 D).
     * No capability gate (ADR-120).
     */
    public function changeDisposition(User $user, Encounter $encounter, ?ChangeEncounterDisposition $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.manage_disposition',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * AssignEncounterLocation — per-subject check; setting the coarse
     * care-context department / unit is gated by its own dedicated key
     * **`encounter.encounters.assign_location`** (ADR-130 — distinct from
     * `encounter.encounters.update`). Same facility-scoped evaluation as the
     * `recordArrival` / `start` / `admit` / `changeDisposition` methods above:
     * the key is checked against the ENCOUNTER's own facility (§114,
     * Invariant 24/25; ADR-130 D). No capability gate (ADR-120); the department
     * / unit ids are not structurally validated (ADR-130 B).
     */
    public function assignLocation(User $user, Encounter $encounter, ?AssignEncounterLocation $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.assign_location',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * RecordEncounterRelationship (ADR-151 D-C) — the ONLY intended caller is
     * `EncounterApi::recordEncounterRelationship`, itself called by Care
     * Coordination's `LinkReferralEncounterHandler`. Dedicated key so this
     * cross-domain write is independently auditable/grantable, distinct from
     * `encounter.encounters.update`.
     */
    public function recordRelationship(User $user, Encounter $encounter, ?RecordEncounterRelationship $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.link_relationship',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * CompleteEncounter — per-subject check; closing an attendance
     * (`ACTIVE → COMPLETED`, §22, §67) is gated by its own dedicated key
     * **`encounter.encounters.complete`** (ADR-130 — distinct from
     * `encounter.encounters.update`). Same facility-scoped evaluation as the
     * `recordArrival` / `start` / `admit` / `changeDisposition` / `assignLocation`
     * methods above: the key is checked against the ENCOUNTER's own facility
     * (§114, Invariant 24/25; ADR-130 D). No capability gate (ADR-120).
     */
    public function complete(User $user, Encounter $encounter, ?CompleteEncounter $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.complete',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * ReopenEncounter — per-subject check; reopening a completed attendance
     * (`COMPLETED → ACTIVE`, §19, §68) is a privileged correction gated by its
     * own dedicated key **`encounter.encounters.reopen`** (ADR-121, ADR-130 —
     * distinct from `encounter.encounters.update` or `complete`). Same
     * facility-scoped evaluation as the other lifecycle methods above: the key
     * is checked against the ENCOUNTER's own facility (§114, Invariant 24/25;
     * ADR-130 D). No capability gate (ADR-120).
     */
    public function reopen(User $user, Encounter $encounter, ?ReopenEncounter $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.reopen',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * CancelEncounter — per-subject check; cancelling an attendance before
     * active care begins (`{PLANNED, ARRIVED} → CANCELLED`, §23, §74) is
     * gated by its own dedicated key **`encounter.encounters.cancel`**
     * (ADR-121, ADR-130 — distinct from `encounter.encounters.update`,
     * `complete`, or `reopen`). Same facility-scoped evaluation as the other
     * lifecycle methods above: the key is checked against the ENCOUNTER's own
     * facility (§114, Invariant 24/25; ADR-130 D). No capability gate (ADR-120).
     */
    public function cancel(User $user, Encounter $encounter, ?CancelEncounter $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.cancel',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * AbandonEncounter — per-subject check; abandoning an attendance
     * (`ARRIVED → LEFT_WITHOUT_BEING_SEEN | ACTIVE → ABANDONED`, §24, §76) is
     * gated by its own dedicated key **`encounter.encounters.abandon`**
     * (ADR-121, ADR-130 — distinct from `encounter.encounters.update`,
     * `cancel`, `complete`, or `reopen`). Same facility-scoped evaluation as the
     * other lifecycle methods above: the key is checked against the ENCOUNTER's
     * own facility (§114, Invariant 24/25; ADR-130 D). No capability gate (ADR-120).
     */
    public function abandon(User $user, Encounter $encounter, ?AbandonEncounter $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.abandon',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }

    /**
     * TransferEncounter — per-subject check; transferring an encounter out to
     * another facility (`ACTIVE → TRANSFERRED`, §25, §90, §91) is gated by its
     * own dedicated key **`encounter.encounters.transfer`** (ADR-121, ADR-130 —
     * distinct from `encounter.encounters.update`, `complete`, `cancel`,
     * `abandon`, or `reopen`). Same facility-scoped evaluation as the other
     * lifecycle methods above: the key is checked against the ENCOUNTER's own
     * facility (§114, Invariant 24/25; ADR-130 D). No capability gate (ADR-120).
     */
    public function transfer(User $user, Encounter $encounter, ?TransferEncounter $command = null): bool
    {
        return $this->sameTenant($user, (string) $encounter->tenant_id)
            && in_array(
                'encounter.encounters.transfer',
                $this->scope->effectivePermissions($user, (string) $encounter->facility_id),
                true,
            );
    }
}
