<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\Batch;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Batch / Lot aggregate (IC §9; ADR-170 D-E).
 */
interface BatchRepository extends Repository
{
    public function getById(string $id): Batch;

    public function getByIdForUpdate(string $id): Batch;

    /** The batch for this (item, batch number) in the tenant, or null (RLS). */
    public function findByItemAndNumber(string $tenantId, string $itemId, string $batchNumber): ?Batch;
}
