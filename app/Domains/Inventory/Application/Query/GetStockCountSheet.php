<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One stock count with its sheet (Phase 8). Loaded only when a count is chosen
 * on the Stock counts screen (`?section=counts&count_id=<id>`), so the ordinary
 * inventory page does not carry every line.
 *
 * Read-only. `view` gates on `inventory.access`; the handler re-checks the
 * actor's scope at the count's facility.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockCountSheet
{
    public function __construct(public string $stockCountId)
    {
        if (! Str::isUuid($stockCountId)) {
            throw new InvalidArgumentException('GetStockCountSheet::$stockCountId must be a UUID (IC §3).');
        }
    }
}
