<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain;

use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for BedOccupancy aggregate (ADR-148 D-A).
 */
interface BedOccupancyRepository extends Repository
{
    public function getById(string $id): BedOccupancy;

    public function getByIdForUpdate(string $id): BedOccupancy;

    public function getActiveForBed(string $bedId): ?BedOccupancy;

    public function getActiveForEncounter(string $encounterId): ?BedOccupancy;
}
