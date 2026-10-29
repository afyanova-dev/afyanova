<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Service;

use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Exception\IllegalEncounterTransition;

/**
 * The single place every encounter state decision passes through
 * (11-encounter-domain.md §19; ADR-121 — "Every transition passes through
 * `Encounter\Domain\Service\EncounterLifecycle`" / "No command writes status
 * directly"). Pure domain logic, no I/O.
 *
 * The full command→state transition table (ADR-121) is built up one rule per
 * lifecycle increment:
 *   - E.1: `initialStatus()` — `ARRIVED` for a walk-in check-in, `PLANNED` only
 *     on an explicit planned intent.
 *   - E.2: `assertCanRecordArrival()` — `PLANNED → ARRIVED` only.
 *   - E.3: `assertCanStart()` — `ARRIVED → ACTIVE` only.
 *   - E.4: `assertCanAdmit()` — `ACTIVE` & type `OUTPATIENT`/`EMERGENCY` only.
 *   - E.5: `assertCanChangeDisposition()` — `ACTIVE` only; the requested value
 *     must be one of the six settable dispositions (not `ADMITTED` / `TRANSFERRED`).
 *   - E.6: `assertCanAssignLocation()` — `ARRIVED` or `ACTIVE` only (the
 *     resolved-no-op check is the aggregate's).
 *   - E.7: `assertCanComplete()` — `ACTIVE` only AND a disposition recorded.
 *   - E.8: `assertCanReopen()` — `COMPLETED → ACTIVE` only.
 *   - E.9: `assertCanCancel()` — `{PLANNED, ARRIVED} → CANCELLED` only.
 *   - E.10: `assertCanAbandon()` — `ARRIVED → LEFT_WITHOUT_BEING_SEEN | ACTIVE → ABANDONED`.
 *   - E.11: `assertCanTransfer()` — `ACTIVE → TRANSFERRED` only (facility exit).
 */
final class EncounterLifecycle
{
    public function initialStatus(bool $planned): EncounterStatus
    {
        return $planned ? EncounterStatus::Planned : EncounterStatus::Arrived;
    }

    /**
     * `RecordArrival: PLANNED → ARRIVED` (ADR-121). Every other source status —
     * including `ARRIVED` (arrival is not idempotent) and every terminal
     * status — is rejected; there is no valid no-op.
     */
    public function assertCanRecordArrival(EncounterStatus $current): void
    {
        if ($current !== EncounterStatus::Planned) {
            throw IllegalEncounterTransition::for('RecordArrival', $current);
        }
    }

    /**
     * `StartEncounter: ARRIVED → ACTIVE` (ADR-121). Every other source status —
     * including `ACTIVE` (starting is not idempotent) and every terminal
     * status — is rejected; there is no valid no-op.
     */
    public function assertCanStart(EncounterStatus $current): void
    {
        if ($current !== EncounterStatus::Arrived) {
            throw IllegalEncounterTransition::for('StartEncounter', $current);
        }
    }

    /**
     * `AdmitEncounter` (ADR-108, ADR-120, ADR-121) — the one sanctioned
     * encounter-type conversion. Legal only from status `ACTIVE` AND type
     * `OUTPATIENT` or `EMERGENCY`. An `INPATIENT` encounter is already admitted
     * (not idempotent — "no reverting INPATIENT", ADR-130 C); `MATERNITY` and
     * every other type are not admissible in PHASE 5. The `INPATIENT_CARE`
     * facility-capability check is the handler's (it is I/O), not this pure
     * guard's.
     */
    public function assertCanAdmit(EncounterStatus $status, EncounterType $type): void
    {
        if ($status !== EncounterStatus::Active) {
            throw IllegalEncounterTransition::for('AdmitEncounter', $status);
        }

        if ($type !== EncounterType::Outpatient && $type !== EncounterType::Emergency) {
            throw IllegalEncounterTransition::typeNotAdmissible($type);
        }
    }

    /**
     * `ChangeEncounterDisposition` (ADR-121: `ACTIVE → ACTIVE`; ADR-130 A) —
     * record or revise the encounter disposition. Legal only from status
     * `ACTIVE` (§12 / §13 place the disposition after clinical care; a completed
     * encounter must be reopened first). The requested value must be one of the
     * six SETTABLE dispositions (`DISCHARGED`, `REFERRED`, `LEFT`, `FOLLOW_UP`,
     * `DECEASED`, `OTHER`); `ADMITTED` is owned by `AdmitEncounter` and
     * `TRANSFERRED` by `TransferEncounter` (ADR-130 A) — setting either here
     * would leave the aggregate inconsistent (no `admitted_at` / no
     * `status = TRANSFERRED`), so both are rejected. The same-value no-op is
     * caught by the aggregate. No capability check — that is I/O and is
     * `CreateEncounter` / `AdmitEncounter` only (ADR-120).
     */
    public function assertCanChangeDisposition(EncounterStatus $status, EncounterDisposition $disposition): void
    {
        if ($status !== EncounterStatus::Active) {
            throw IllegalEncounterTransition::for('ChangeEncounterDisposition', $status);
        }

        if ($disposition === EncounterDisposition::Admitted || $disposition === EncounterDisposition::Transferred) {
            throw IllegalEncounterTransition::dispositionNotSettable($disposition);
        }
    }

    /**
     * `AssignEncounterLocation` (ADR-121: `ARRIVED or ACTIVE → unchanged`;
     * ADR-130 B) — set / change the coarse care-context `department_id` /
     * `unit_id`. Legal only from status `ARRIVED` or `ACTIVE` (a `PLANNED`
     * encounter has no attendance yet; a completed / terminal one must be
     * reopened first). Status is never changed. The resolved-no-op check and the
     * patch itself are the aggregate's; there is no structural validation of the
     * ids (ADR-130 B — trusted server-side inputs) and no capability check.
     */
    public function assertCanAssignLocation(EncounterStatus $status): void
    {
        if ($status !== EncounterStatus::Arrived && $status !== EncounterStatus::Active) {
            throw IllegalEncounterTransition::for('AssignEncounterLocation', $status);
        }
    }

    /**
     * `CompleteEncounter` (ADR-121: `ACTIVE & disposition set → COMPLETED`;
     * ADR-130 A) — close the attendance. Legal only from status `ACTIVE` AND
     * with a disposition already recorded (`$disposition !== null` — ADR-121
     * "disposition required"; `CompleteEncounter` does not pick or restrict the
     * value, so ANY non-null `EncounterDisposition` is accepted, including
     * `ADMITTED` — R-1). Completing an already-`COMPLETED` encounter is rejected
     * by the status check (not idempotent). No capability check.
     */
    public function assertCanComplete(EncounterStatus $status, ?EncounterDisposition $disposition): void
    {
        if ($status !== EncounterStatus::Active) {
            throw IllegalEncounterTransition::for('CompleteEncounter', $status);
        }

        if ($disposition === null) {
            throw IllegalEncounterTransition::dispositionRequired();
        }
    }

    /**
     * `ReopenEncounter: COMPLETED → ACTIVE` (ADR-121; ADR-130 C, Rulings E8-R1..E8-R8) —
     * reopen a completed encounter for correction. Legal only from status
     * `COMPLETED`. Every other source status — including `ACTIVE` (reopening is
     * not idempotent) and all other terminal statuses (`CANCELLED`,
     * `LEFT_WITHOUT_BEING_SEEN`, `ABANDONED`, `TRANSFERRED`) — is rejected;
     * there is no valid no-op. No capability check.
     */
    public function assertCanReopen(EncounterStatus $status): void
    {
        if ($status !== EncounterStatus::Completed) {
            throw IllegalEncounterTransition::for('ReopenEncounter', $status);
        }
    }

    /**
     * `CancelEncounter: {PLANNED, ARRIVED} → CANCELLED` (11-encounter-domain.md §23,
     * §74; ADR-121; ADR-130 A/D) — cancel an attendance before active care
     * begins. Legal from `PLANNED` (pre-arrival appointment/booking) or
     * `ARRIVED` (checked-in patient leaving before consultation begins). Every
     * other source status — including `ACTIVE` (care already commenced; must use
     * `AbandonEncounter` or `CompleteEncounter`), `COMPLETED` (§23 — completed
     * attendances cannot be cancelled), and terminal `CANCELLED` (cancellation
     * is not idempotent) — is rejected with IllegalEncounterTransition (409).
     * No capability check.
     */
    public function assertCanCancel(EncounterStatus $status): void
    {
        if (! in_array($status, [EncounterStatus::Planned, EncounterStatus::Arrived], true)) {
            throw IllegalEncounterTransition::for('CancelEncounter', $status);
        }
    }

    /**
     * `AbandonEncounter: ARRIVED → LEFT_WITHOUT_BEING_SEEN | ACTIVE → ABANDONED`
     * (11-encounter-domain.md §24, §76; ADR-121; ADR-130 A/D) — record that a
     * patient left before consultation or mid-care. Legal from `ARRIVED`
     * (checked-in patient leaving before consultation begins -> LWBS) or `ACTIVE`
     * (care commenced -> ABANDONED). Every other source status — including
     * `PLANNED` (patient never arrived; must use CancelEncounter), `COMPLETED`
     * (care completed normally), and all terminal statuses (`CANCELLED`,
     * `LEFT_WITHOUT_BEING_SEEN`, `ABANDONED`, `TRANSFERRED`, `REFERRED`) — is
     * rejected with IllegalEncounterTransition (409). No capability check.
     */
    public function assertCanAbandon(EncounterStatus $status): EncounterStatus
    {
        return match ($status) {
            EncounterStatus::Arrived => EncounterStatus::LeftWithoutBeingSeen,
            EncounterStatus::Active => EncounterStatus::Abandoned,
            default => throw IllegalEncounterTransition::for('AbandonEncounter', $status),
        };
    }

    /**
     * `TransferEncounter: ACTIVE → TRANSFERRED` (11-encounter-domain.md §19, §25,
     * §90, §91, §169; ADR-121; ADR-130 A/C/E) — the patient is leaving this
     * facility for another; this attendance ends. Legal only from status
     * `ACTIVE`. Every other source status — including `ARRIVED` (care not
     * started; a patient leaving now is `AbandonEncounter` → `LEFT_WITHOUT_BEING_SEEN`),
     * `COMPLETED`, and every terminal status (`CANCELLED`,
     * `LEFT_WITHOUT_BEING_SEEN`, `ABANDONED`, `TRANSFERRED` — transfer is not
     * idempotent, `REFERRED`) — is rejected with IllegalEncounterTransition (409).
     * No capability check. The receiving facility opens its OWN encounter
     * (§8, §91) — not this command's concern (ADR-130 Deferred).
     */
    public function assertCanTransfer(EncounterStatus $status): void
    {
        if ($status !== EncounterStatus::Active) {
            throw IllegalEncounterTransition::for('TransferEncounter', $status);
        }
    }
}
