<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\Supplier;
use App\Shared\Domain\Repository;

/** Write-side port for the Supplier aggregate (IC §9; ADR-171 D-C). */
interface SupplierRepository extends Repository
{
    public function getById(string $id): Supplier;

    public function getByIdForUpdate(string $id): Supplier;

    /** The non-BLACKLISTED supplier with this `code` in the tenant, or null (RLS). */
    public function findActiveCode(string $tenantId, string $code): ?Supplier;
}
