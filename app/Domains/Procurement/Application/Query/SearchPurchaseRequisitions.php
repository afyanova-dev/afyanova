<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The purchase requisitions at a facility, optionally filtered by status
 * (ADR-171 D-J). Facility-scoped — the handler adds an ADR-133 refinement at
 * `$facilityId` (the Inv.1 `GetStockAdjustments` precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: PurchaseRequisition::class)]
final readonly class SearchPurchaseRequisitions
{
    public function __construct(
        public string $facilityId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchPurchaseRequisitions::$facilityId must be a UUID (IC §3).');
        }
    }
}
