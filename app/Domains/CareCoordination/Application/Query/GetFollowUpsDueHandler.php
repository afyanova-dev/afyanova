<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Contracts\Dto\FollowUpSummary;
use App\Domains\CareCoordination\Domain\FollowUpReader;
use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetFollowUpsDueHandler
{
    public function __construct(
        private readonly FollowUpReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<FollowUpSummary>
     */
    public function __invoke(GetFollowUpsDue $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetFollowUpsDue dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('care_coordination.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(FollowUp::class, $query->facilityId);
        }

        return $this->reader->dueForFacility($query->facilityId, $query->from, $query->to);
    }
}
