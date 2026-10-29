<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Contracts\Dto\FacilityOccupancySummary;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Domains\BedWard\Domain\WardReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetFacilityOccupancyHandler
{
    public function __construct(
        private readonly WardReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetFacilityOccupancy $query): FacilityOccupancySummary
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetFacilityOccupancy dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('bed_ward.access', $this->scope->effectivePermissions($user, $query->facilityId), true)) {
            throw NotFound::aggregate(Ward::class, $query->facilityId);
        }

        return $this->reader->facilityOccupancy($query->facilityId);
    }
}
