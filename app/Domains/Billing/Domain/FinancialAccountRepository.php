<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Financial Account aggregate (IC §9; ADR-187 D-B).
 * The FIRST repository parameter of the financial-account commands — the bus
 * resolves `FinancialAccountPolicy` from it.
 *
 * Implementation: `Infrastructure/Repository/EloquentFinancialAccountRepository`,
 * bound in `BillingServiceProvider`.
 */
interface FinancialAccountRepository extends Repository
{
    public function getById(string $id): FinancialAccount;

    public function getByIdForUpdate(string $id): FinancialAccount;

    /**
     * True if a non-closed Financial Account already exists for this patient at
     * this facility in the current tenant scope — the `OpenFinancialAccount`
     * pre-check (the partial unique index is the race backstop; ADR-187 D-B).
     */
    public function hasOpenAccountForPatientAtFacility(string $patientId, string $facilityId): bool;

    /**
     * The non-closed Financial Account for a (patient, facility), `lockForUpdate()`,
     * or null (ADR-189 D-I — the lazy-open path; the lock serializes concurrent
     * `CaptureBillableEvent`s for one patient).
     */
    public function openAccountForPatientAtFacilityForUpdate(string $patientId, string $facilityId): ?FinancialAccount;
}
