<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Contracts\Dto\SupplierSummary;

/** Read-side port for suppliers (IC §6.2 / §6.3; ADR-171 D-J). Returns DTOs only. */
interface SupplierReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the tenant scope (RLS). */
    public function summary(string $supplierId): SupplierSummary;

    /**
     * The tenant's suppliers, optionally filtered.
     *
     * @return list<SupplierSummary>
     */
    public function list(?string $status = null, ?string $category = null): array;
}
