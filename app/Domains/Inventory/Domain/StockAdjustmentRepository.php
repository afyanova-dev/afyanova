<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Stock Adjustment workflow aggregate (IC §9;
 * ADR-170 D-H).
 */
interface StockAdjustmentRepository extends Repository
{
    public function getById(string $id): StockAdjustment;

    public function getByIdForUpdate(string $id): StockAdjustment;
}
