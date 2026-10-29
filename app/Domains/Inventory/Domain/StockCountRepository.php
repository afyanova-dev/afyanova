<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\StockCount;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Stock Count document
 * (frontend-foundation-inventory-operations.md Phase 8, stock counts).
 *
 * The count row emits no events (it is a record, not a movement), so `save()`
 * is inherited unchanged.
 */
interface StockCountRepository extends Repository
{
    public function getById(string $id): StockCount;

    public function getByIdForUpdate(string $id): StockCount;
}
