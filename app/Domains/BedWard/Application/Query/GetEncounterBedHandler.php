<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Contracts\Dto\BedOccupancySummary;
use App\Domains\BedWard\Domain\BedOccupancyReader;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetEncounterBedHandler
{
    public function __construct(
        private readonly BedOccupancyReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return array{current: BedOccupancySummary|null, history: list<BedOccupancySummary>}
     */
    public function __invoke(GetEncounterBed $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetEncounterBed dispatched without an authenticated user (IC §6.1).');
        }

        $result = $this->reader->currentAndHistoryForEncounter($query->encounterId);

        $facilityId = null;
        if ($result['current'] !== null) {
            $facilityId = $result['current']->facilityId;
        } elseif (isset($result['history'][0])) {
            $facilityId = $result['history'][0]->facilityId;
        }

        if ($facilityId !== null && ! in_array('bed_ward.access', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(BedOccupancy::class, $query->encounterId);
        }

        return $result;
    }
}
