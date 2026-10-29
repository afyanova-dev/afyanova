<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\CloseClinicalPlan;
use App\Domains\Clinical\Application\Command\CreateClinicalPlan;
use App\Domains\Clinical\Application\Command\UpdateClinicalPlan;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * ClinicalPlan aggregate policy (ADR-143 D-O / D-P).
 *
 * Keys:
 *   - clinical.plans.read   -> view (GetClinicalPlan, GetPatientClinicalPlans)
 *   - clinical.plans.record -> record (CreateClinicalPlan), update (UpdateClinicalPlan),
 *                              close (CloseClinicalPlan)
 */
final class ClinicalPlanPolicy extends BasePolicy
{
    public function record(User $user, ?CreateClinicalPlan $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.plans.record');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.plans.read');
    }

    public function update(User $user, ClinicalPlan $plan, ?UpdateClinicalPlan $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $plan);
    }

    public function close(User $user, ClinicalPlan $plan, ?CloseClinicalPlan $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $plan);
    }

    private function recordAtOriginatingFacility(User $user, ClinicalPlan $plan): bool
    {
        return $this->sameTenant($user, (string) $plan->tenant_id)
            && in_array(
                'clinical.plans.record',
                $this->scope->effectivePermissions($user, (string) $plan->facility_id),
                true,
            );
    }
}
