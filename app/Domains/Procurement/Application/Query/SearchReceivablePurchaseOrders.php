<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * P0-03 — search the open POs a receiving clerk can select, without injecting
 * every open order and every line into the Inventory page.
 */
#[ProtectedQuery(ability: 'view', subjectType: PurchaseOrder::class)]
final readonly class SearchReceivablePurchaseOrders
{
    public function __construct(
        public string $facilityId,
        public string $query = '',
        public int $page = 1,
        public int $perPage = 20,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchReceivablePurchaseOrders::$facilityId must be a UUID (IC §3).');
        }

        if (mb_strlen(trim($query)) > 100) {
            throw new InvalidArgumentException('SearchReceivablePurchaseOrders::$query must be at most 100 characters.');
        }

        if ($page < 1 || $perPage < 1 || $perPage > 50) {
            throw new InvalidArgumentException('SearchReceivablePurchaseOrders paging must be page >= 1 and perPage 1–50.');
        }
    }
}
