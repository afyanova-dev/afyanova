<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockByLocation
{
    public const SORT_COLUMNS = ['code', 'name', 'on_hand', 'reserved', 'available'];

    public function __construct(
        public string $locationId,
        public int $page = 1,
        public int $perPage = 15,
        public string $sortBy = 'code',
        public string $sortDirection = 'asc',
        public string $search = '',
    ) {
        if (! Str::isUuid($locationId)) {
            throw new InvalidArgumentException('GetStockByLocation::$locationId must be a UUID (IC §3).');
        }
        if ($page < 1 || ! in_array($perPage, [15, 30, 50, 100], true)) {
            throw new InvalidArgumentException('GetStockByLocation pagination is outside the supported range.');
        }
        if (! in_array($sortBy, self::SORT_COLUMNS, true) || ! in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('GetStockByLocation sorting is invalid.');
        }
        if (mb_strlen($search) > 100) {
            throw new InvalidArgumentException('GetStockByLocation search must be at most 100 characters.');
        }
    }
}
