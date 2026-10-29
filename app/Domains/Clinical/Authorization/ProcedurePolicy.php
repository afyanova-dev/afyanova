<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\AbortProcedure;
use App\Domains\Clinical\Application\Command\AddProcedureNote;
use App\Domains\Clinical\Application\Command\AmendProcedure;
use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Command\CancelProcedure;
use App\Domains\Clinical\Application\Command\CompleteProcedure;
use App\Domains\Clinical\Application\Command\DeclineProcedure;
use App\Domains\Clinical\Application\Command\MarkProcedureReady;
use App\Domains\Clinical\Application\Command\RecordProcedureMaterials;
use App\Domains\Clinical\Application\Command\RecordProcedureOutcome;
use App\Domains\Clinical\Application\Command\RequestProcedure;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * ClinicalProcedure aggregate policy (IC §12; 13-clinical-procedures-domain.md
 * §96, §148, §168–§169; ADR-133, ADR-146 D-O / D-P). The `TriageAssessmentPolicy`
 * / `DiagnosisPolicy` shape:
 *
 *   - `request` is CLASS-LEVEL (`permits()` — no subject exists yet);
 *   - `authorize` / `markReady` / `cancel` / `decline` (the `request` key),
 *     `start` / `abort` / `recordOutcome` / `addNote` / `recordMaterials` (the
 *     `perform` key), `complete` (the `complete` key) and `amend` (the `amend`
 *     key) are PER-SUBJECT — the bus loaded the procedure from its (RLS-scoped)
 *     repository, so the check runs against the PROCEDURE's own facility via
 *     `ScopeChecker::effectivePermissions` (§88, Invariant 24/25) — the ADR-133
 *     originating-facility refinement (R-6);
 *   - `view` is CLASS-LEVEL — the QueryBus never loads a subject; the
 *     per-record / per-caller facility refinement is each query handler's own
 *     (ADR-146 D-Q).
 *
 * FIVE keys (ADR-146 D-O): `clinical.procedures.{read, request, perform,
 * complete, amend}` — deliberately finer than the C.3–C.9 `{read, record}`
 * collapse because a procedure is a multi-actor WORKFLOW (13 §21, §141), not a
 * single-actor documentation act. Care-team membership is NOT an authorization
 * input (ADR-133 / 12 §159). Break-glass / sensitivity remain DEFERRED
 * (13 §168–§169; the ADR-133 deferral). `authored_context` is NOT an
 * authorization input (the ADR-144 mechanism).
 */
final class ProcedurePolicy extends BasePolicy
{
    /**
     * RequestProcedure — class-level (create) check; there is no subject yet
     * (IC §5.1). `RequestProcedureHandler` adds the per-encounter-facility
     * refinement (ADR-146 D-G).
     */
    public function request(User $user, ?RequestProcedure $command = null): bool
    {
        $permission = $command?->protocolReference !== null
            ? 'clinical.procedures.protocol_request'
            : 'clinical.procedures.request';

        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $permission);
    }

    /** AuthorizeProcedure — per-subject, the `request` key (ADR-146 D-O). */
    public function authorize(User $user, ClinicalProcedure $procedure, ?AuthorizeProcedure $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.request');
    }

    /** MarkProcedureReady — per-subject, the `request` key (ADR-146 D-O). */
    public function markReady(User $user, ClinicalProcedure $procedure, ?MarkProcedureReady $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.request');
    }

    /** CancelProcedure — per-subject, the `request` key (ADR-146 D-O). */
    public function cancel(User $user, ClinicalProcedure $procedure, ?CancelProcedure $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.request');
    }

    /** DeclineProcedure — per-subject, the `request` key (ADR-146 D-O). */
    public function decline(User $user, ClinicalProcedure $procedure, ?DeclineProcedure $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.request');
    }

    /** StartProcedure — per-subject, the `perform` key (ADR-146 D-O). */
    public function start(User $user, ClinicalProcedure $procedure, ?StartProcedure $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.perform');
    }

    /** AbortProcedure — per-subject, the `perform` key (ADR-146 D-O). */
    public function abort(User $user, ClinicalProcedure $procedure, ?AbortProcedure $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.perform');
    }

    /** RecordProcedureOutcome — per-subject, the `perform` key (ADR-146 D-O). */
    public function recordOutcome(User $user, ClinicalProcedure $procedure, ?RecordProcedureOutcome $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.perform');
    }

    /**
     * AddProcedureNote — per-subject, the `perform` key (ADR-146 D-O). The
     * nested `CreateClinicalNote` dispatch ADDITIONALLY requires
     * `clinical.notes.create` via `ClinicalNotePolicy::create` — enforced by
     * that command's own bus authorization, not here (ADR-146 D-L).
     */
    public function addNote(User $user, ClinicalProcedure $procedure, ?AddProcedureNote $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.perform');
    }

    /** RecordProcedureMaterials — per-subject, the `perform` key (ADR-146 D-O). */
    public function recordMaterials(User $user, ClinicalProcedure $procedure, ?RecordProcedureMaterials $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.perform');
    }

    /**
     * CompleteProcedure — per-subject, its OWN `complete` key (ADR-146 D-O) —
     * the terminal fact that fires the Billing-triggering `ProcedureCompleted`
     * event; an organisation may restrict "who declares this done and
     * billable" separately from `perform`.
     */
    public function complete(User $user, ClinicalProcedure $procedure, ?CompleteProcedure $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.complete');
    }

    /**
     * AmendProcedure — per-subject, the privileged `amend` key (ADR-146 D-O /
     * D-M — the `clinical.notes.amend` precedent).
     */
    public function amend(User $user, ClinicalProcedure $procedure, ?AmendProcedure $command = null): bool
    {
        return $this->scopedTo($user, $procedure, 'clinical.procedures.amend');
    }

    /**
     * GetProcedure / GetProcedureDefinition / GetProcedureDefinitions /
     * GetEncounterProcedures / GetPatientProcedures (`#[ProtectedQuery]`,
     * IC §6.2) — class-level check; the QueryBus never loads a subject. Coarse
     * tenant + `clinical.procedures.read`. The per-record / per-caller facility
     * refinement is each query handler's own (ADR-146 D-Q).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.procedures.read');
    }

    /**
     * The shared per-subject shape: same tenant + the given key held at the
     * PROCEDURE's own facility (§88, Invariant 24/25).
     */
    private function scopedTo(User $user, ClinicalProcedure $procedure, string $key): bool
    {
        return $this->sameTenant($user, (string) $procedure->tenant_id)
            && in_array($key, $this->scope->effectivePermissions($user, (string) $procedure->facility_id), true);
    }
}
