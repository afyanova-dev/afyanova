<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: PurchaseOrder::class)]
final readonly class GetGoodsReceiptPurchaseOrder
{
    public function __construct(
        public string $facilityId,
        public string $poNumber,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetGoodsReceiptPurchaseOrder::$facilityId must be a UUID (IC §3).');
        }
        if (trim($poNumber) === '') {
            throw new InvalidArgumentException('GetGoodsReceiptPurchaseOrder::$poNumber must not be empty.');
        }
    }
}
