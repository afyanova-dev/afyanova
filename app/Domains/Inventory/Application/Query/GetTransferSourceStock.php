<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Everything currently held at one location, with its batches — the "move
 * stock" panel's source list (Phase 8). Loading it only when a source location
 * is chosen keeps the ordinary inventory page unchanged.
 *
 * Read-only. `view` gates on `inventory.access`; the handler re-checks the
 * actor's scope at the location's facility.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetTransferSourceStock
{
    public function __construct(public string $locationId)
    {
        if (! Str::isUuid($locationId)) {
            throw new InvalidArgumentException('GetTransferSourceStock::$locationId must be a UUID (IC §3).');
        }
    }
}
