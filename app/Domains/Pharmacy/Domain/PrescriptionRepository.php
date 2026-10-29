<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain;

use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Prescription aggregate (IC §9; ADR-181 D-D).
 * This is the FIRST repository parameter of the prescription commands — the bus
 * resolves `PrescriptionPolicy` from it.
 *
 * Implementation: `Infrastructure/Repository/EloquentPrescriptionRepository`,
 * bound in `PharmacyServiceProvider`.
 */
interface PrescriptionRepository extends Repository
{
    public function getById(string $id): Prescription;

    public function getByIdForUpdate(string $id): Prescription;

    /**
     * The prescription for one prescribing decision in the current tenant scope,
     * or null — the `IngestPrescription` idempotency check (ADR-181 D-E).
     */
    public function findByPrescribingDecisionId(string $prescribingDecisionId): ?Prescription;
}
