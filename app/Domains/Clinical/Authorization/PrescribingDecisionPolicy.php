<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\CorrectPrescribingDecision;
use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecision;
use App\Domains\Clinical\Application\Command\RecordPrescribingDecision;
use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * PrescribingDecision aggregate policy (ADR-143 D-O / D-P).
 *
 * Keys:
 *   - clinical.prescribing.read   -> view (GetPrescribingDecision, GetPatientPrescribingDecisions)
 *   - clinical.prescribing.record -> record (RecordPrescribingDecision), correct (CorrectPrescribingDecision),
 *                                    discontinue (DiscontinuePrescribingDecision)
 */
final class PrescribingDecisionPolicy extends BasePolicy
{
    public function record(User $user, ?RecordPrescribingDecision $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.prescribing.record');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.prescribing.read');
    }

    public function correct(User $user, PrescribingDecision $decision, ?CorrectPrescribingDecision $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $decision);
    }

    public function discontinue(User $user, PrescribingDecision $decision, ?DiscontinuePrescribingDecision $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $decision);
    }

    private function recordAtOriginatingFacility(User $user, PrescribingDecision $decision): bool
    {
        return $this->sameTenant($user, (string) $decision->tenant_id)
            && in_array(
                'clinical.prescribing.record',
                $this->scope->effectivePermissions($user, (string) $decision->facility_id),
                true,
            );
    }
}
