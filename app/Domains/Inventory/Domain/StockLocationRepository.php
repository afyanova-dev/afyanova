<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Stock Location catalog (IC §9; ADR-170 D-D). Used
 * ONLY by the `inventory:define-location` console command.
 */
interface StockLocationRepository extends Repository
{
    public function getById(string $id): StockLocation;

    public function getByIdForUpdate(string $id): StockLocation;

    /** The active location with this `code` at a facility, or null (RLS). */
    public function findActiveByCode(string $tenantId, string $facilityId, string $code): ?StockLocation;
}
