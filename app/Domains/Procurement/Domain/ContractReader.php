<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Contracts\Dto\ContractSummary;

/**
 * Read-side port for purchase contracts (IC §6.2 / §6.3; ADR-176 D-F).
 * Tenant-scoped; every method is gated on `procurement.access`.
 */
interface ContractReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $contractId): ContractSummary;

    /**
     * Contracts for the tenant, optionally filtered by supplier / status.
     *
     * @return list<ContractSummary>
     */
    public function search(?string $supplierId = null, ?string $status = null): array;
}
