<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Shared\Domain\Repository;

/** Write-side port for the PurchaseRequisition aggregate (IC §9; ADR-171 D-E). */
interface PurchaseRequisitionRepository extends Repository
{
    public function getById(string $id): PurchaseRequisition;

    public function getByIdForUpdate(string $id): PurchaseRequisition;
}
