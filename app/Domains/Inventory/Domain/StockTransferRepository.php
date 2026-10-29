<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\StockTransfer;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Stock Transfer document (Phase 8).
 *
 * The transfer row emits no events; the two balances emit `StockTransferred`
 * when their sides are applied, so `save()` is inherited unchanged.
 */
interface StockTransferRepository extends Repository
{
    public function getById(string $id): StockTransfer;

    public function getByIdForUpdate(string $id): StockTransfer;
}
