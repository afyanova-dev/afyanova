<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\Charge;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Charge aggregate (IC §9; ADR-189 D-C).
 */
interface ChargeRepository extends Repository
{
    public function getById(string $id): Charge;

    public function getByIdForUpdate(string $id): Charge;
}
