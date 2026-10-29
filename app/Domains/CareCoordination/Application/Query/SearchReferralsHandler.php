<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SearchReferralsHandler
{
    public function __construct(
        private readonly ReferralReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<ReferralSummary>
     */
    public function __invoke(SearchReferrals $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchReferrals dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('care_coordination.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(Referral::class, $query->facilityId);
        }

        return $this->reader->inboundQueueForFacility($query->facilityId);
    }
}
