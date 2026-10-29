<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;

/**
 * Read one goods receipt and its lines (Phase 1 / P1-07–P1-08).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetGoodsReceipt
{
    public function __construct(public string $goodsReceiptId) {}
}
