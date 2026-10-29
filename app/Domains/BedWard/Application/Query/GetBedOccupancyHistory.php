<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Get occupancy history for a bed (24-bed-and-ward-management-domain.md §10; ADR-148 D-I).
 */
#[ProtectedQuery(ability: 'view', subjectType: BedOccupancy::class)]
final readonly class GetBedOccupancyHistory
{
    public function __construct(public string $bedId)
    {
        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('GetBedOccupancyHistory::$bedId must be a UUID (IC §3).');
        }
    }
}
