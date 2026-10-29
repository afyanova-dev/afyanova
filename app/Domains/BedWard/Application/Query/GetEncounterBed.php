<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Get current bed and occupancy history for an encounter (24-bed-and-ward-management-domain.md §10; ADR-148 D-I).
 */
#[ProtectedQuery(ability: 'view', subjectType: BedOccupancy::class)]
final readonly class GetEncounterBed
{
    public function __construct(public string $encounterId)
    {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('GetEncounterBed::$encounterId must be a UUID (IC §3).');
        }
    }
}
