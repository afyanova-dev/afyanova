<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The purchase orders at a facility, optionally filtered by status (ADR-171
 * D-J). Facility-scoped (ADR-133 refinement in the handler).
 */
#[ProtectedQuery(ability: 'list', subjectType: PurchaseOrder::class)]
final readonly class SearchPurchaseOrders
{
    public function __construct(
        public string $facilityId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchPurchaseOrders::$facilityId must be a UUID (IC §3).');
        }
    }
}
