<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\Adjustment;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the adjustment aggregate (IC §9; ADR-191 D7-D). The FIRST
 * repository parameter of the adjustment commands — the bus resolves
 * `AdjustmentPolicy` from it.
 */
interface AdjustmentRepository extends Repository
{
    public function getById(string $id): Adjustment;

    public function getByIdForUpdate(string $id): Adjustment;

    public function exists(string $id): bool;
}
