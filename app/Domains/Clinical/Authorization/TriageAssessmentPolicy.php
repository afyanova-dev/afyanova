<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\CorrectTriageAssessment;
use App\Domains\Clinical\Application\Command\RecordTriageAssessment;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * TriageAssessment aggregate policy (IC §12; 12-clinical-domain.md §87–§90,
 * §159; ADR-133, ADR-145 D-L). The exact `DiagnosisPolicy` shape:
 *
 *   - class-level checks (`record`, `view`) use `permits()` — the key in the
 *     actor's CURRENT facility context;
 *   - the per-subject check (`correct`) evaluates the key against the
 *     ASSESSMENT's own facility via `ScopeChecker::effectivePermissions`,
 *     because `clin_triage_assessments` RLS is tenant-scoped (§88, Invariant
 *     24/25).
 *
 * The handlers add a further ADR-145 refinement where the class-level check
 * cannot reach the target facility (`RecordTriageAssessment` against the
 * encounter's facility; `GetTriageAssessment` against the assessment's
 * facility; `GetEncounterTriageAssessments` against the caller's in-scope
 * facilities).
 *
 * Exactly two keys (ADR-145 D-L): `clinical.triage.read` and
 * `clinical.triage.record`. **Correction / re-triage is authorized by
 * `clinical.triage.record`** — there is no `clinical.triage.correct` / `.amend`
 * / `.update` / `.manage` key. Care-team membership is NOT an authorization
 * input (`12` §159). Break-glass / sensitivity remain DEFERRED (ADR-133). The
 * triage priority is NOT an authorization input for anything (ADR-145 D-L).
 */
final class TriageAssessmentPolicy extends BasePolicy
{
    /**
     * RecordTriageAssessment — class-level (create) check; there is no subject
     * yet (IC §5.1). `permits()` resolves `clinical.triage.record` in the
     * actor's current facility context; `RecordTriageAssessmentHandler` adds the
     * per-encounter-facility refinement (ADR-145 D-G).
     */
    public function record(User $user, ?RecordTriageAssessment $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.triage.record');
    }

    /**
     * GetTriageAssessment / GetEncounterTriageAssessments (`#[ProtectedQuery]`,
     * IC §6.2) — class-level check; the QueryBus never loads a subject. Coarse
     * tenant + `clinical.triage.read`. The per-record / per-caller facility
     * refinement is the handlers' (ADR-145 D-H).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.triage.read');
    }

    /**
     * CorrectTriageAssessment — per-subject check; the bus loaded the assessment
     * being corrected / re-triaged from its (RLS-scoped) repository. Gated by
     * the **`clinical.triage.record`** key (ADR-145 D-G / D-L — the same key as
     * recording; a correction / re-triage is a new record). Evaluated against
     * the ASSESSMENT's own facility (§88, Invariant 24/25; ADR-145).
     */
    public function correct(User $user, TriageAssessment $assessment, ?CorrectTriageAssessment $command = null): bool
    {
        return $this->sameTenant($user, (string) $assessment->tenant_id)
            && in_array(
                'clinical.triage.record',
                $this->scope->effectivePermissions($user, (string) $assessment->facility_id),
                true,
            );
    }
}
