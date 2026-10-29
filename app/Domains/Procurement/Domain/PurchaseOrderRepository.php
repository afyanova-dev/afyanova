<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Domain\Repository;

/** Write-side port for the PurchaseOrder aggregate (IC §9; ADR-171 D-G). */
interface PurchaseOrderRepository extends Repository
{
    public function getById(string $id): PurchaseOrder;

    public function getByIdForUpdate(string $id): PurchaseOrder;

    /**
     * The PO with this `po_number` in the tenant, locked `FOR UPDATE`, or null
     * (RLS-scoped) — the `StockReceived` reaction's lookup key (ADR-172 D-B).
     */
    public function findByNumberForUpdate(string $poNumber): ?PurchaseOrder;
}
