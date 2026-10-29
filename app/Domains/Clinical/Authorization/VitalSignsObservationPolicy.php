<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\CorrectVitalSignsObservation;
use App\Domains\Clinical\Application\Command\RecordVitalSigns;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * VitalSignsObservation aggregate policy (IC §12; 12-clinical-domain.md
 * §87–§90, §159; ADR-133, ADR-138 D16). The exact `EncounterPolicy` /
 * `ClinicalNotePolicy` shape:
 *
 *   - class-level checks (`record`, `view`) use `permits()` — the key in the
 *     actor's CURRENT facility context;
 *   - the per-subject check (`correct`) evaluates the key against the
 *     OBSERVATION's own facility via `ScopeChecker::effectivePermissions`,
 *     because `clin_vital_sign_observations` RLS is tenant-scoped (§88,
 *     Invariant 24/25).
 *
 * The handlers add a further ADR-133 refinement where the class-level check
 * cannot reach the target facility (`RecordVitalSigns` against the encounter's
 * facility; `GetVitalSignsObservation` against the observation's facility).
 *
 * Exactly two keys (ADR-138 D16): `clinical.vitals.read` and
 * `clinical.vitals.record`. **Correction is authorized by `clinical.vitals.record`**
 * — there is no `clinical.vitals.correct` / `.amend` / `.update` / `.cancel` /
 * `.verify` key. Care-team membership is NOT an authorization input (`12` §159).
 */
final class VitalSignsObservationPolicy extends BasePolicy
{
    /**
     * RecordVitalSigns — class-level (create) check; there is no subject yet
     * (IC §5.1). `permits()` resolves `clinical.vitals.record` in the actor's
     * current facility context; `RecordVitalSignsHandler` adds the
     * per-encounter-facility refinement (ADR-133).
     */
    public function record(User $user, ?RecordVitalSigns $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.vitals.record');
    }

    /**
     * GetVitalSignsObservation (`#[ProtectedQuery]`, IC §6.2) — class-level
     * check; the QueryBus never loads a subject. Coarse tenant +
     * `clinical.vitals.read`. The per-observation facility refinement is
     * `GetVitalSignsObservationHandler`'s (ADR-133).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.vitals.read');
    }

    /**
     * CorrectVitalSignsObservation — per-subject check; the bus loaded the
     * observation being corrected from its (RLS-scoped) repository. Gated by the
     * **`clinical.vitals.record`** key (ADR-138 D16 — the same key as recording;
     * a correction is a new record). Evaluated against the OBSERVATION's own
     * facility (§88, Invariant 24/25; ADR-133).
     */
    public function correct(User $user, VitalSignsObservation $observation, ?CorrectVitalSignsObservation $command = null): bool
    {
        return $this->sameTenant($user, (string) $observation->tenant_id)
            && in_array(
                'clinical.vitals.record',
                $this->scope->effectivePermissions($user, (string) $observation->facility_id),
                true,
            );
    }
}
