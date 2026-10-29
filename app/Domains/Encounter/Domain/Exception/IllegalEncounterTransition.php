<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Exception;

use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Domain\DomainException;

/**
 * A lifecycle command was dispatched against an encounter it cannot legally act
 * on — the current status is not a legal source for that command's transition,
 * OR (for `AdmitEncounter`) the encounter's type is not admissible, OR (for
 * `ChangeEncounterDisposition`) the requested disposition is not settable by
 * that command or is already the current value, OR (for `AssignEncounterLocation`)
 * the resolved location is unchanged, OR (for `CompleteEncounter`) no disposition
 * has been recorded yet (11-encounter-domain.md §19, Invariant 15; ADR-121 —
 * "invalid transitions raise DomainException" / "disposition required"; ADR-120 —
 * only OUTPATIENT / EMERGENCY may be admitted; ADR-130 A — ADMITTED / TRANSFERRED
 * belong to their own commands). Validated by
 * `Encounter\Domain\Service\EncounterLifecycle` (and, for the same-value cases,
 * the aggregate) before any mutation. Maps to HTTP 409 (§15).
 *
 * Reusable by every Encounter lifecycle increment — `RecordArrival` (E.2),
 * `StartEncounter` (E.3), `AdmitEncounter` (E.4), `ChangeEncounterDisposition`
 * (E.5), `AssignEncounterLocation` (E.6), `CompleteEncounter` (E.7),
 * `ReopenEncounter` (E.8), `CancelEncounter` (E.9), `AbandonEncounter` (E.10),
 * `TransferEncounter` (E.11).
 */
final class IllegalEncounterTransition extends DomainException
{
    public static function for(string $command, EncounterStatus $current): self
    {
        return new self(sprintf(
            '%s is not a legal transition from status %s.',
            $command,
            $current->value,
        ));
    }

    /**
     * `AdmitEncounter` — the encounter's status is `ACTIVE` but its type is not
     * one of {OUTPATIENT, EMERGENCY} (ADR-120): an INPATIENT encounter is
     * already admitted, and no other type may be admitted in PHASE 5.
     */
    public static function typeNotAdmissible(EncounterType $current): self
    {
        return new self(sprintf(
            'AdmitEncounter cannot convert an encounter of type %s — only OUTPATIENT / EMERGENCY may be admitted.',
            $current->value,
        ));
    }

    /**
     * `ChangeEncounterDisposition` — the requested disposition is `ADMITTED` or
     * `TRANSFERRED` (ADR-130 A): those are set only by `AdmitEncounter` /
     * `TransferEncounter`, each alongside the type / status change that makes
     * them coherent. `ChangeEncounterDisposition` cannot create either.
     */
    public static function dispositionNotSettable(EncounterDisposition $requested): self
    {
        return new self(sprintf(
            'ChangeEncounterDisposition cannot set disposition %s — it is set only by %s.',
            $requested->value,
            $requested === EncounterDisposition::Admitted ? 'AdmitEncounter' : 'TransferEncounter',
        ));
    }

    /**
     * `ChangeEncounterDisposition` — the encounter already carries the requested
     * disposition. The command is an explicit state change, not an idempotent
     * no-op (consistent with `RecordArrival` / `StartEncounter` / `AdmitEncounter`).
     */
    public static function dispositionUnchanged(EncounterDisposition $current): self
    {
        return new self(sprintf(
            'ChangeEncounterDisposition: the encounter disposition is already %s — there is no change to make.',
            $current->value,
        ));
    }

    /**
     * `AssignEncounterLocation` — the resolved (`department_id`, `unit_id`) pair
     * equals the encounter's current location. The command is an explicit
     * change, not an idempotent no-op (consistent with the other lifecycle
     * commands; ADR-130 B).
     */
    public static function locationUnchanged(): self
    {
        return new self(
            'AssignEncounterLocation: the encounter is already at this department / unit — there is no change to make.',
        );
    }

    /**
     * `CompleteEncounter` — the encounter is `ACTIVE` but no disposition has been
     * recorded (ADR-121 — "disposition required"; ADR-130 A). Record one with
     * `ChangeEncounterDisposition` first.
     */
    public static function dispositionRequired(): self
    {
        return new self(
            'CompleteEncounter requires a disposition to be recorded first — use ChangeEncounterDisposition.',
        );
    }

    public function errorCode(): string
    {
        return 'illegal_encounter_transition';
    }
}
