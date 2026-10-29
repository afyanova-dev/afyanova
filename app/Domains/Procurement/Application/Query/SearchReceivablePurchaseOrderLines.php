<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * P0-03 — one page of open STOCK lines for the selected receivable PO.
 */
#[ProtectedQuery(ability: 'view', subjectType: PurchaseOrder::class)]
final readonly class SearchReceivablePurchaseOrderLines
{
    public function __construct(
        public string $facilityId,
        public string $purchaseOrderId,
        public string $query = '',
        public int $page = 1,
        public int $perPage = 100,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchReceivablePurchaseOrderLines::$facilityId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($purchaseOrderId)) {
            throw new InvalidArgumentException('SearchReceivablePurchaseOrderLines::$purchaseOrderId must be a UUID (IC §3).');
        }

        if (mb_strlen(trim($query)) > 100) {
            throw new InvalidArgumentException('SearchReceivablePurchaseOrderLines::$query must be at most 100 characters.');
        }

        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('SearchReceivablePurchaseOrderLines paging must be page >= 1 and perPage 1–100.');
        }
    }
}
