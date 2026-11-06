<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\ReferralReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetReferralHandler
{
    public function __construct(
        private readonly ReferralReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetReferral $query): ReferralSummary
    {
        $summary = $this->reader->summary($query->referralId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetReferral dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'care_coordination.access')) {
            throw NotFound::aggregate(Referral::class, $query->referralId);
        }

        return $summary;
    }
}
