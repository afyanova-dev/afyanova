<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Authorization;

use App\Domains\CareCoordination\Application\Command\RecordFollowUpOutcome;
use App\Domains\CareCoordination\Application\Command\ScheduleFollowUp;
use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * FollowUp aggregate policy (ADR-154 D-E/D-H). One key backs both write
 * abilities: `care_coordination.followup.manage`.
 */
final class FollowUpPolicy extends BasePolicy
{
    /**
     * `ScheduleFollowUp` carries no facility — it is taken from the source
     * encounter by the handler, which also runs the ADR-133
     * per-encounter-facility refinement. Coarse, any-facility check only.
     */
    public function create(User $user, ?ScheduleFollowUp $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'care_coordination.followup.manage');
    }

    public function manage(User $user, FollowUp $followUp, ?RecordFollowUpOutcome $command = null): bool
    {
        return $this->sameTenant($user, (string) $followUp->tenant_id)
            && in_array(
                'care_coordination.followup.manage',
                $this->scope->effectivePermissions($user, (string) $followUp->facility_id),
                true,
            );
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'care_coordination.access');
    }
}
