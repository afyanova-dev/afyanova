<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Authorization;

use App\Domains\CareCoordination\Application\Command\CompleteReferralFeedback;
use App\Domains\CareCoordination\Application\Command\MarkFeedbackNotProvided;
use App\Domains\CareCoordination\Application\Command\RecordReferralFeedback;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * ReferralFeedback aggregate policy (ADR-151 D-A/D-K). Feedback is recorded
 * by the receiving/accepting facility about the outcome, reported back to
 * the referrer — `ReferralFeedback::facility_id` is set to that receiving
 * facility at creation (`AcceptReferralHandler`).
 */
final class ReferralFeedbackPolicy extends BasePolicy
{
    public function record(User $user, ReferralFeedback $feedback, ?RecordReferralFeedback $command = null): bool
    {
        return $this->hasScope($user, $feedback);
    }

    public function complete(User $user, ReferralFeedback $feedback, ?CompleteReferralFeedback $command = null): bool
    {
        return $this->hasScope($user, $feedback);
    }

    public function markNotProvided(User $user, ReferralFeedback $feedback, ?MarkFeedbackNotProvided $command = null): bool
    {
        return $this->hasScope($user, $feedback);
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'care_coordination.access');
    }

    private function hasScope(User $user, ReferralFeedback $feedback): bool
    {
        return $this->sameTenant($user, (string) $feedback->tenant_id)
            && in_array(
                'care_coordination.feedback.record',
                $this->scope->effectivePermissions($user, (string) $feedback->facility_id),
                true,
            );
    }
}
