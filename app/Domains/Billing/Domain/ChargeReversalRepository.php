<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\ChargeReversal;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the append-only ChargeReversal aggregate (IC §9;
 * ADR-189 D-D).
 */
interface ChargeReversalRepository extends Repository
{
    public function getById(string $id): ChargeReversal;

    public function getByIdForUpdate(string $id): ChargeReversal;

    /** True if a reversal already exists for this charge (INV-2 backstop). */
    public function existsForCharge(string $chargeId): bool;
}
