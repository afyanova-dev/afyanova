<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Contracts\Dto\StockLocationSummary;

/**
 * Read-side port for the Stock Location catalog (IC §6.2 / §6.3; ADR-170 D-M).
 * Returns DTOs only.
 */
interface StockLocationReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $locationId): StockLocationSummary;

    /**
     * The stock locations at a facility, optionally filtered by active flag.
     *
     * @return list<StockLocationSummary>
     */
    public function listForFacility(
        string $facilityId,
        ?bool $active = null,
        ?array $departmentIds = null,
    ): array;
}
