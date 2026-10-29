<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Facility-wide stock-on-hand listing — one row per (item, location, batch)
 * (§30, §327; ADR-170 D-M). Distinct from `GetStockByLocation` (one location)
 * and `GetAvailableStock` (one item): this answers "what stock is at this
 * facility?" across every location, paged and filterable. `view` gates on
 * `inventory.access`; the handler re-checks the acting user's scope.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockByFacility
{
    public const SORT_COLUMNS = ['code', 'location', 'on_hand', 'reserved', 'available'];

    public function __construct(
        public string $facilityId,
        public int $page = 1,
        public int $perPage = 15,
        public string $sortBy = 'code',
        public string $sortDirection = 'asc',
        public string $search = '',
        public ?string $locationId = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetStockByFacility::$facilityId must be a UUID (IC §3).');
        }
        if ($locationId !== null && ! Str::isUuid($locationId)) {
            throw new InvalidArgumentException('GetStockByFacility::$locationId must be a UUID or null (IC §3).');
        }
        if ($page < 1 || ! in_array($perPage, [15, 30, 50, 100], true)) {
            throw new InvalidArgumentException('GetStockByFacility pagination is outside the supported range.');
        }
        if (! in_array($sortBy, self::SORT_COLUMNS, true) || ! in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('GetStockByFacility sorting is invalid.');
        }
        if (mb_strlen($search) > 100) {
            throw new InvalidArgumentException('GetStockByFacility search must be at most 100 characters.');
        }
    }
}
