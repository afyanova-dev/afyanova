<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every item minimum level set at one facility
 * (frontend-foundation-inventory-operations.md Phase 8). Used by the Running
 * low screen to show which items are already tracked and what their current
 * minimum is, so changing a level is never blind.
 *
 * Read-only. `view` gates on `inventory.access`; the handler re-checks the
 * actor's scope at the facility.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetItemLevels
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetItemLevels::$facilityId must be a UUID (IC §3).');
        }
    }
}
