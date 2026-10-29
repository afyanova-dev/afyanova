<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\ImagingStudySummary;
use App\Domains\Radiology\Domain\ImagingStudyReader;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetRadiologyWorklistHandler
{
    public function __construct(
        private readonly ImagingStudyReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<ImagingStudySummary>
     */
    public function __invoke(GetRadiologyWorklist $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetRadiologyWorklist dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('radiology.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(ImagingStudy::class, $query->facilityId);
        }

        return $this->reader->worklistForFacility($query->facilityId, $query->statuses);
    }
}
