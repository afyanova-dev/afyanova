<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Goods Receipt document (Phase 1).
 *
 * The receipt header emits `GoodsReceiptPosted`; lines and stock movements are
 * written by the command handler in the same transaction. `save()` therefore
 * uses the base implementation unchanged.
 */
interface GoodsReceiptRepository extends Repository
{
    public function getById(string $id): GoodsReceipt;

    public function getByIdForUpdate(string $id): GoodsReceipt;
}
