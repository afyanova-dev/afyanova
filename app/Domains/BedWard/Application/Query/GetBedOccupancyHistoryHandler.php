<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Contracts\Dto\BedOccupancySummary;
use App\Domains\BedWard\Domain\BedOccupancyReader;
use App\Domains\BedWard\Domain\BedReader;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetBedOccupancyHistoryHandler
{
    public function __construct(
        private readonly BedOccupancyReader $reader,
        private readonly BedReader $bedReader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<BedOccupancySummary>
     */
    public function __invoke(GetBedOccupancyHistory $query): array
    {
        $bedSummary = $this->bedReader->summary($query->bedId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetBedOccupancyHistory dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('bed_ward.access', $this->scope->effectivePermissions($user, $bedSummary->facilityId), true)) {
            throw NotFound::aggregate(BedOccupancy::class, $query->bedId);
        }

        return $this->reader->historyForBed($query->bedId);
    }
}
