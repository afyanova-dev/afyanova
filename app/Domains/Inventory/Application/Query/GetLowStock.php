<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Low-stock worklist for one facility: every item whose usable on-hand quantity
 * is at or below the minimum level set AT THAT FACILITY
 * (frontend-foundation-inventory-operations.md Phase 8).
 *
 * The minimum is per facility because the item master is shared by the whole
 * tenant, but "running low" is not: a dispensary and a hospital buy the same
 * item at completely different scales. An item with no level is not tracked and
 * never appears.
 *
 * Read-only. `view` gates on `inventory.access`; the handler re-checks the
 * actor's scope at the facility.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetLowStock
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetLowStock::$facilityId must be a UUID (IC §3).');
        }
    }
}
