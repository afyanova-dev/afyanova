<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Pharmacy Order aggregate (IC §9; ADR-182 D-C).
 * The FIRST repository parameter of the pharmacy-order commands — the bus
 * resolves `PharmacyOrderPolicy` from it.
 *
 * Implementation: `Infrastructure/Repository/EloquentPharmacyOrderRepository`,
 * bound in `PharmacyServiceProvider`.
 */
interface PharmacyOrderRepository extends Repository
{
    public function getById(string $id): PharmacyOrder;

    public function getByIdForUpdate(string $id): PharmacyOrder;

    /**
     * True if a non-terminal Pharmacy Order already exists for this prescription
     * in the current tenant scope — the `CreatePharmacyOrder` pre-check
     * (the partial unique index is the race backstop; ADR-182 D-B).
     */
    public function hasOpenOrderForPrescription(string $prescriptionId): bool;
}
