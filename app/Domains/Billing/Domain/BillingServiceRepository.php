<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\BillingService;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the billing-service catalog aggregate (IC §9; ADR-188 D-A).
 * The FIRST repository parameter of the catalog commands — the bus resolves
 * `BillingCatalogPolicy` from it.
 */
interface BillingServiceRepository extends Repository
{
    public function getById(string $id): BillingService;

    public function getByIdForUpdate(string $id): BillingService;

    /** True if a billing service with this code exists in the current tenant scope. */
    public function existsByCode(string $code): bool;
}
