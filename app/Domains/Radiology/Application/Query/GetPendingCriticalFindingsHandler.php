<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\RadiologyCriticalFindingSummary;
use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Domains\Radiology\Domain\RadiologyCriticalFindingReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPendingCriticalFindingsHandler
{
    public function __construct(
        private readonly RadiologyCriticalFindingReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<RadiologyCriticalFindingSummary>
     */
    public function __invoke(GetPendingCriticalFindings $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPendingCriticalFindings dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('radiology.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(RadiologyCriticalFinding::class, $query->facilityId);
        }

        return $this->reader->pendingForFacility($query->facilityId);
    }
}
