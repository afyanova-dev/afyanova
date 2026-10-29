<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\ReferralFeedbackSummary;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralFeedbackReader;
use App\Domains\CareCoordination\Domain\ReferralReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetReferralFeedbackHandler
{
    public function __construct(
        private readonly ReferralReader $referrals,
        private readonly ReferralFeedbackReader $feedback,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<ReferralFeedbackSummary>
     */
    public function __invoke(GetReferralFeedback $query): array
    {
        $referral = $this->referrals->summary($query->referralId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetReferralFeedback dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('care_coordination.access', $this->scope->effectivePermissions($user, $referral->facilityId), true)) {
            throw NotFound::aggregate(Referral::class, $query->referralId);
        }

        return $this->feedback->forReferral($query->referralId);
    }
}
