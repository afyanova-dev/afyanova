<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\ReferralFeedbackSummary;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralFeedbackReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetReferralFeedbackDueHandler
{
    public function __construct(
        private readonly ReferralFeedbackReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<ReferralFeedbackSummary>
     */
    public function __invoke(GetReferralFeedbackDue $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetReferralFeedbackDue dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('care_coordination.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(Referral::class, $query->facilityId);
        }

        return $this->reader->due($query->facilityId);
    }
}
