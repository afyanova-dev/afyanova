<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\RecordAllergy;
use App\Domains\Clinical\Application\Command\ResolveAllergy;
use App\Domains\Clinical\Application\Command\UpdateAllergy;
use App\Domains\Clinical\Application\Command\VerifyAllergy;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Allergy aggregate policy (IC §12; 12-clinical-domain.md §87–§90, §159;
 * ADR-133, ADR-141 D-L / D-M). The exact `DiagnosisPolicy` / `ProblemPolicy`
 * shape:
 *
 *   - class-level checks (`record`, `view`) use `permits()` — the key in the
 *     actor's CURRENT facility context;
 *   - the per-subject checks (`update`, `verify`, `resolve`) evaluate the
 *     `clinical.allergies.record` key against the ALLERGY's ORIGINATING
 *     facility via `ScopeChecker::effectivePermissions`, because `clin_allergies`
 *     RLS is tenant-scoped (§88, Invariant 24/25; ADR-141 D-B — WRITES stay
 *     originating-facility-refined).
 *
 * >>> Q7 (ADR-141 D-M): `view` is COARSE ONLY — tenant + `clinical.allergies.read`.
 *     `GetAllergyHandler` / `GetPatientAllergiesHandler` do NO originating-facility
 *     refinement (deliberately unlike `GetDiagnosisHandler` / `GetProblemHandler`):
 *     allergy READS are TENANT-WIDE within the tenant, because a critical allergy
 *     is a patient-safety fact. This is an Allergy-read-path-only refinement —
 *     `ClinicalNote` / `VitalSignsObservation` / `Diagnosis` / `Problem` reads
 *     keep their facility refinement. RLS still confines reads to the tenant. <<<
 *
 * Exactly two keys (ADR-141 D-L): `clinical.allergies.read` and
 * `clinical.allergies.record`. **`record` authorizes Record, Update, Verify AND
 * Resolve** — there is no `clinical.allergies.manage` / `.update` / `.verify` /
 * `.resolve` / `.correct` / `.retract` key. Care-team membership is NOT an
 * authorization input (`12` §159). Break-glass / sensitivity remain DEFERRED
 * (ADR-133) — C.5 adds no such model.
 */
final class AllergyPolicy extends BasePolicy
{
    /**
     * RecordAllergy — class-level (create) check; there is no subject yet
     * (IC §5.1). `permits()` resolves `clinical.allergies.record` in the actor's
     * current facility context; `RecordAllergyHandler` adds the
     * per-encounter-facility refinement (ADR-141 D-K).
     */
    public function record(User $user, ?RecordAllergy $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.allergies.record');
    }

    /**
     * GetAllergy / GetPatientAllergies (`#[ProtectedQuery]`, IC §6.2) —
     * class-level check; the QueryBus never loads a subject. Coarse tenant +
     * `clinical.allergies.read`. **No per-allergy originating-facility
     * refinement** — allergy reads are tenant-wide (ADR-141 D-M).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.allergies.read');
    }

    /**
     * UpdateAllergy — per-subject check; the bus loaded the allergy being
     * revised / corrected from its (RLS-scoped) repository. Gated by the
     * **`clinical.allergies.record`** key (ADR-141 D-L — the same key as
     * recording). Evaluated against the ALLERGY's ORIGINATING facility (§88,
     * Invariant 24/25; ADR-141 D-B — WRITES stay facility-refined).
     */
    public function update(User $user, Allergy $allergy, ?UpdateAllergy $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $allergy);
    }

    /**
     * VerifyAllergy — per-subject check; identical gate to {@see update()}
     * (ADR-141 D-L — `record` authorizes Verify; there is no
     * `clinical.allergies.verify` key).
     */
    public function verify(User $user, Allergy $allergy, ?VerifyAllergy $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $allergy);
    }

    /**
     * ResolveAllergy — per-subject check; identical gate to {@see update()}
     * (ADR-141 D-L — `record` authorizes Resolve).
     */
    public function resolve(User $user, Allergy $allergy, ?ResolveAllergy $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $allergy);
    }

    private function recordAtOriginatingFacility(User $user, Allergy $allergy): bool
    {
        return $this->sameTenant($user, (string) $allergy->tenant_id)
            && in_array(
                'clinical.allergies.record',
                $this->scope->effectivePermissions($user, (string) $allergy->facility_id),
                true,
            );
    }
}
