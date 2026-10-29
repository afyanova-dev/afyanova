<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A catalog console command named a `code` that already belongs to an ACTIVE
 * item / stock location in scope (18-inventory-and-warehousing-domain.md §165,
 * §166, §167; ADR-170 D-C / D-D — the CP.1 / Lab.1 / Rad.1 catalog
 * code-unique-among-active rule). A retired code may be reused. Maps to
 * HTTP 409.
 */
final class InventoryCatalogConflict extends DomainException
{
    public static function itemCode(string $tenantId, string $code): self
    {
        return new self(sprintf('An active inventory item with code [%s] already exists in tenant [%s].', $code, $tenantId));
    }

    public static function locationCode(string $facilityId, string $code): self
    {
        return new self(sprintf('An active stock location with code [%s] already exists at facility [%s].', $code, $facilityId));
    }

    public static function locationTypeCode(string $code): self
    {
        return new self(sprintf('A stock location type with code [%s] already exists in this tenant.', $code));
    }

    public function errorCode(): string
    {
        return 'inventory_catalog_conflict';
    }
}
