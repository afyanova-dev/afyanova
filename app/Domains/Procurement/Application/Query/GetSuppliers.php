<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Supplier;
use App\Shared\Bus\ProtectedQuery;

/**
 * List the tenant's suppliers, optionally filtered by status / category (ADR-171
 * D-J). Tenant-wide, NO pagination (the CP.1 / Inv.1 catalog-read precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: Supplier::class)]
final readonly class GetSuppliers
{
    public function __construct(
        public ?string $status = null,
        public ?string $category = null,
    ) {}
}
