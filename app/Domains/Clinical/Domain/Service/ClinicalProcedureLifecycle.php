<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Service;

use App\Domains\Clinical\Domain\Enum\ClinicalProcedureStatus;
use App\Domains\Clinical\Domain\Exception\IllegalProcedureTransition;

/**
 * The single place every clinical-procedure state decision passes through
 * (13-clinical-procedures-domain.md §32, §138; ADR-137 — "every transition
 * passes a ClinicalProcedureLifecycle domain service"; ADR-146 D-F). Pure
 * domain logic, no I/O.
 *
 * The CP.1 transition table (ADR-146 D-F):
 *
 *   RequestProcedure         (new)        → REQUESTED   (origination — no guard)
 *   AuthorizeProcedure       REQUESTED    → AUTHORIZED   {@see assertCanAuthorize}
 *   MarkProcedureReady       AUTHORIZED   → READY        {@see assertCanMarkReady} (ONLY if the definition requires it)
 *   StartProcedure           AUTHORIZED / READY → IN_PROGRESS {@see assertCanStart}
 *   CompleteProcedure        IN_PROGRESS  → COMPLETED    {@see assertCanComplete}
 *   CancelProcedure          {REQUESTED, AUTHORIZED, READY} → CANCELLED {@see assertCanCancel}
 *   AbortProcedure           IN_PROGRESS  → ABORTED      {@see assertCanAbort}
 *   DeclineProcedure         {AUTHORIZED, READY} → DECLINED {@see assertCanDecline}
 *   RecordProcedureOutcome   IN_PROGRESS  → IN_PROGRESS   {@see assertCanRecordOutcome} (pre-completion only — R-4/S3)
 *   AddProcedureNote         {IN_PROGRESS, COMPLETED, ABORTED} {@see assertCanAddNote}
 *   RecordProcedureMaterials {IN_PROGRESS, COMPLETED} {@see assertCanRecordMaterials}
 *   AmendProcedure           {COMPLETED, ABORTED} {@see assertCanAmend}
 *
 * `SCHEDULED` / `NO_SHOW` are not in the CP.1 set (ADR-137 — Scheduling,
 * PHASE 11).
 */
final class ClinicalProcedureLifecycle
{
    /**
     * `AuthorizeProcedure: REQUESTED → AUTHORIZED` (13 §21 — always an
     * explicit transition). Legal only from `REQUESTED`.
     */
    public function assertCanAuthorize(ClinicalProcedureStatus $current): void
    {
        if ($current !== ClinicalProcedureStatus::Requested) {
            throw IllegalProcedureTransition::for('AuthorizeProcedure', $current);
        }
    }

    /**
     * `MarkProcedureReady: AUTHORIZED → READY` — legal only from `AUTHORIZED`
     * AND only when the definition's `requires_readiness_check` is true
     * (ADR-146 D-F); otherwise the direct `AUTHORIZED → IN_PROGRESS` path
     * applies ({@see assertCanStart}).
     */
    public function assertCanMarkReady(ClinicalProcedureStatus $current, bool $requiresReadinessCheck): void
    {
        if ($current !== ClinicalProcedureStatus::Authorized) {
            throw IllegalProcedureTransition::for('MarkProcedureReady', $current);
        }

        if (! $requiresReadinessCheck) {
            throw IllegalProcedureTransition::readinessNotRequired('MarkProcedureReady');
        }
    }

    /**
     * `StartProcedure: {AUTHORIZED, READY} → IN_PROGRESS` (ADR-146 D-F). Legal
     * from `READY` unconditionally, or from `AUTHORIZED` ONLY when the
     * definition does NOT require a readiness check (the small-facility fast
     * path) — an `AUTHORIZED` procedure whose definition DOES require
     * readiness must pass through `READY` first.
     */
    public function assertCanStart(ClinicalProcedureStatus $current, bool $requiresReadinessCheck): void
    {
        if ($current === ClinicalProcedureStatus::Ready) {
            return;
        }

        if ($current === ClinicalProcedureStatus::Authorized && ! $requiresReadinessCheck) {
            return;
        }

        throw IllegalProcedureTransition::for('StartProcedure', $current);
    }

    /**
     * `CompleteProcedure: IN_PROGRESS → COMPLETED`. Legal only from
     * `IN_PROGRESS`; a repeated completion is not idempotent.
     */
    public function assertCanComplete(ClinicalProcedureStatus $current): void
    {
        if ($current !== ClinicalProcedureStatus::InProgress) {
            throw IllegalProcedureTransition::for('CompleteProcedure', $current);
        }
    }

    /**
     * `CancelProcedure: {REQUESTED, AUTHORIZED, READY} → CANCELLED` (13 §34).
     * Legal only before execution has started.
     */
    public function assertCanCancel(ClinicalProcedureStatus $current): void
    {
        if (! in_array($current, [
            ClinicalProcedureStatus::Requested,
            ClinicalProcedureStatus::Authorized,
            ClinicalProcedureStatus::Ready,
        ], true)) {
            throw IllegalProcedureTransition::for('CancelProcedure', $current);
        }
    }

    /**
     * `AbortProcedure: IN_PROGRESS → ABORTED` (13 §35 — distinct from
     * cancellation before starting). Legal only from `IN_PROGRESS`.
     */
    public function assertCanAbort(ClinicalProcedureStatus $current): void
    {
        if ($current !== ClinicalProcedureStatus::InProgress) {
            throw IllegalProcedureTransition::for('AbortProcedure', $current);
        }
    }

    /**
     * `DeclineProcedure: {AUTHORIZED, READY} → DECLINED` (13 §37; ADR-146 D-F —
     * `AUTHORIZED` is a source state because `READY` is now
     * definition-conditional; a patient refusing a fast-path procedure must
     * still be recorded as a refusal, not a cancellation — 13 §4332).
     */
    public function assertCanDecline(ClinicalProcedureStatus $current): void
    {
        if (! in_array($current, [ClinicalProcedureStatus::Authorized, ClinicalProcedureStatus::Ready], true)) {
            throw IllegalProcedureTransition::for('DeclineProcedure', $current);
        }
    }

    /**
     * `RecordProcedureOutcome` — legal ONLY while `IN_PROGRESS` (ADR-146 D-I /
     * S3 / O-2): it keys off status, not off whether `READY` occurred. ANY
     * post-`COMPLETED` outcome change goes through `AmendProcedure` instead —
     * a completed record is corrected only under the privileged `amend` key.
     */
    public function assertCanRecordOutcome(ClinicalProcedureStatus $current): void
    {
        if ($current !== ClinicalProcedureStatus::InProgress) {
            throw IllegalProcedureTransition::for('RecordProcedureOutcome', $current);
        }
    }

    /**
     * `AddProcedureNote` — legal while the procedure is being executed or has
     * reached a terminal execution state that still warrants documentation
     * (`IN_PROGRESS`, `COMPLETED`, `ABORTED`). Not legal before execution
     * starts (`REQUESTED` / `AUTHORIZED` / `READY`) or after a pre-execution
     * `CANCELLED` / `DECLINED` (nothing was performed to document).
     */
    public function assertCanAddNote(ClinicalProcedureStatus $current): void
    {
        if (! in_array($current, [
            ClinicalProcedureStatus::InProgress,
            ClinicalProcedureStatus::Completed,
            ClinicalProcedureStatus::Aborted,
        ], true)) {
            throw IllegalProcedureTransition::for('AddProcedureNote', $current);
        }
    }

    /**
     * `RecordProcedureMaterials` — legal "during or after" execution (13 §52):
     * `IN_PROGRESS` or `COMPLETED`.
     */
    public function assertCanRecordMaterials(ClinicalProcedureStatus $current): void
    {
        if (! in_array($current, [ClinicalProcedureStatus::InProgress, ClinicalProcedureStatus::Completed], true)) {
            throw IllegalProcedureTransition::for('RecordProcedureMaterials', $current);
        }
    }

    /**
     * `AmendProcedure` — the privileged post-execution correction (ADR-146
     * D-M). Legal only from a terminal execution state that produced facts
     * worth correcting: `COMPLETED` or `ABORTED`.
     */
    public function assertCanAmend(ClinicalProcedureStatus $current): void
    {
        if (! in_array($current, [ClinicalProcedureStatus::Completed, ClinicalProcedureStatus::Aborted], true)) {
            throw IllegalProcedureTransition::for('AmendProcedure', $current);
        }
    }
}
