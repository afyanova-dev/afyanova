<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use App\Domains\Inventory\Domain\Model\Item;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Item Master catalog (IC §9; ADR-170 D-C). Used ONLY
 * by the `inventory:define-item` console command — there is no HTTP bus command
 * for catalog authoring in Inv.1 (the CP.1 / Lab.1 / Rad.1 precedent).
 */
interface ItemRepository extends Repository
{
    public function getById(string $id): Item;

    public function getByIdForUpdate(string $id): Item;

    /** The active item with this `code`, or null (tenant-scoped, RLS). */
    public function findActiveByCode(string $tenantId, string $code): ?Item;
}
