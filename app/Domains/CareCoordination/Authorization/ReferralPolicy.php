<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Authorization;

use App\Domains\CareCoordination\Application\Command\AcceptReferral;
use App\Domains\CareCoordination\Application\Command\AcknowledgeCareHandover;
use App\Domains\CareCoordination\Application\Command\CancelReferral;
use App\Domains\CareCoordination\Application\Command\CompleteReferral;
use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Application\Command\LinkReferralEncounter;
use App\Domains\CareCoordination\Application\Command\ReceiveReferral;
use App\Domains\CareCoordination\Application\Command\RejectReferral;
use App\Domains\CareCoordination\Application\Command\SendReferral;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Referral aggregate policy (ADR-151 D-A/D-K).
 */
final class ReferralPolicy extends BasePolicy
{
    public function create(User $user, ?CreateReferral $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'care_coordination.referral.send');
    }

    public function send(User $user, Referral $referral, ?SendReferral $command = null): bool
    {
        return $this->hasScope($user, $referral, (string) $referral->facility_id, 'care_coordination.referral.send');
    }

    public function cancel(User $user, Referral $referral, ?CancelReferral $command = null): bool
    {
        return $this->hasScope($user, $referral, (string) $referral->facility_id, 'care_coordination.referral.send');
    }

    public function receive(User $user, Referral $referral, ?ReceiveReferral $command = null): bool
    {
        return $this->hasScope($user, $referral, $referral->receivingFacilityId(), 'care_coordination.referral.receive');
    }

    public function accept(User $user, Referral $referral, ?AcceptReferral $command = null): bool
    {
        return $this->hasScope($user, $referral, $referral->receivingFacilityId(), 'care_coordination.referral.receive');
    }

    public function reject(User $user, Referral $referral, ?RejectReferral $command = null): bool
    {
        return $this->hasScope($user, $referral, $referral->receivingFacilityId(), 'care_coordination.referral.receive');
    }

    public function complete(User $user, Referral $referral, ?CompleteReferral $command = null): bool
    {
        return $this->hasScope($user, $referral, $referral->receivingFacilityId(), 'care_coordination.referral.receive');
    }

    public function linkEncounter(User $user, Referral $referral, ?LinkReferralEncounter $command = null): bool
    {
        return $this->hasScope($user, $referral, $referral->receivingFacilityId(), 'care_coordination.referral.receive');
    }

    public function acknowledgeHandover(User $user, Referral $referral, ?AcknowledgeCareHandover $command = null): bool
    {
        return $this->hasScope($user, $referral, $referral->receivingFacilityId(), 'care_coordination.handover.acknowledge');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'care_coordination.access');
    }

    private function hasScope(User $user, Referral $referral, string $facilityId, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $referral->tenant_id)
            && in_array($permissionKey, $this->scope->effectivePermissions($user, $facilityId), true);
    }
}
