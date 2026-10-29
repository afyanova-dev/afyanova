<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabCriticalResultSummary;
use App\Domains\Laboratory\Domain\LabCriticalResultReader;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPendingCriticalResultsHandler
{
    public function __construct(
        private readonly LabCriticalResultReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<LabCriticalResultSummary>
     */
    public function __invoke(GetPendingCriticalResults $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPendingCriticalResults dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('laboratory.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(LabCriticalResult::class, $query->facilityId);
        }

        return $this->reader->pendingForFacility($query->facilityId);
    }
}
