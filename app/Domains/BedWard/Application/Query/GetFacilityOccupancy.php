<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Domain\Model\Ward;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Live occupancy rate and counts for a facility (24-bed-and-ward-management-domain.md §10; ADR-148 D-I).
 */
#[ProtectedQuery(ability: 'view', subjectType: Ward::class)]
final readonly class GetFacilityOccupancy
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetFacilityOccupancy::$facilityId must be a UUID (IC §3).');
        }
    }
}
