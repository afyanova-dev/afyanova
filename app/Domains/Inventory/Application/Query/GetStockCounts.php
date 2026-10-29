<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Recent stock counts for one facility (Phase 8) — the Stock counts screen.
 *
 * Read-only. `view` gates on `inventory.access`; the handler re-checks the
 * actor's scope at the facility.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockCounts
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public function __construct(
        public string $facilityId,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetStockCounts::$facilityId must be a UUID (IC §3).');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('GetStockCounts::$limit is outside the supported range.');
        }
    }
}
