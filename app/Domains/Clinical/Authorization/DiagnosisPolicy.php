<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\CorrectDiagnosis;
use App\Domains\Clinical\Application\Command\RecordDiagnosis;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Diagnosis aggregate policy (IC §12; 12-clinical-domain.md §87–§90, §159;
 * ADR-133, ADR-139 D9). The exact `EncounterPolicy` / `ClinicalNotePolicy` /
 * `VitalSignsObservationPolicy` shape:
 *
 *   - class-level checks (`record`, `view`) use `permits()` — the key in the
 *     actor's CURRENT facility context;
 *   - the per-subject check (`correct`) evaluates the key against the
 *     DIAGNOSIS's own facility via `ScopeChecker::effectivePermissions`,
 *     because `clin_diagnoses` RLS is tenant-scoped (§88, Invariant 24/25).
 *
 * The handlers add a further ADR-139 refinement where the class-level check
 * cannot reach the target facility (`RecordDiagnosis` against the encounter's
 * facility; `GetDiagnosis` against the diagnosis's facility).
 *
 * Exactly two keys (ADR-139 D9): `clinical.diagnoses.read` and
 * `clinical.diagnoses.record`. **Correction is authorized by
 * `clinical.diagnoses.record`** — there is no `clinical.diagnoses.correct` /
 * `.amend` / `.update` / `.manage` / `.resolve` / `.cancel` / `.void` key.
 * Care-team membership is NOT an authorization input (`12` §159). Break-glass /
 * sensitivity remain DEFERRED (ADR-133).
 */
final class DiagnosisPolicy extends BasePolicy
{
    /**
     * RecordDiagnosis — class-level (create) check; there is no subject yet
     * (IC §5.1). `permits()` resolves `clinical.diagnoses.record` in the actor's
     * current facility context; `RecordDiagnosisHandler` adds the
     * per-encounter-facility refinement (ADR-139).
     */
    public function record(User $user, ?RecordDiagnosis $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.diagnoses.record');
    }

    /**
     * GetDiagnosis (`#[ProtectedQuery]`, IC §6.2) — class-level check; the
     * QueryBus never loads a subject. Coarse tenant + `clinical.diagnoses.read`.
     * The per-diagnosis facility refinement is `GetDiagnosisHandler`'s (ADR-139).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.diagnoses.read');
    }

    /**
     * CorrectDiagnosis — per-subject check; the bus loaded the diagnosis being
     * corrected from its (RLS-scoped) repository. Gated by the
     * **`clinical.diagnoses.record`** key (ADR-139 D9 — the same key as
     * recording; a correction is a new record). Evaluated against the
     * DIAGNOSIS's own facility (§88, Invariant 24/25; ADR-139).
     */
    public function correct(User $user, Diagnosis $diagnosis, ?CorrectDiagnosis $command = null): bool
    {
        return $this->sameTenant($user, (string) $diagnosis->tenant_id)
            && in_array(
                'clinical.diagnoses.record',
                $this->scope->effectivePermissions($user, (string) $diagnosis->facility_id),
                true,
            );
    }
}
