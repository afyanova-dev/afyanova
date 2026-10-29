<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;

/**
 * Search the facility's goods-receipt register (Phase 1 / P1-08).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class SearchGoodsReceipts
{
    public function __construct(
        public string $facilityId,
        public string $query = '',
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
