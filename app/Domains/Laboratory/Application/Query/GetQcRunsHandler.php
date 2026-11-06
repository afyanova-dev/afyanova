<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabQcRunSummary;
use App\Domains\Laboratory\Domain\LabQcRunReader;
use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetQcRunsHandler
{
    public function __construct(
        private readonly LabQcRunReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<LabQcRunSummary>
     */
    public function __invoke(GetQcRuns $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetQcRuns dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $query->facilityId, 'laboratory.access')) {
            throw NotFound::aggregate(LabQcRun::class, $query->facilityId);
        }

        return $this->reader->recentForFacility($query->facilityId);
    }
}
