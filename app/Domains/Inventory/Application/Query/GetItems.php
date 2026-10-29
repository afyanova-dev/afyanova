<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;

/**
 * Read the tenant's Item Master catalog, optionally filtered (ADR-170 D-M).
 * Tenant-wide; NO pagination (catalogs are small — the CP.1 / Lab.1 / Rad.1
 * precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetItems
{
    public function __construct(
        public ?string $itemType = null,
        public ?bool $active = null,
    ) {}
}
