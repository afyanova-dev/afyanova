<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * P2-02 — price evidence for a supplier/item at the active facility.
 */
#[ProtectedQuery(ability: 'view', subjectType: PurchaseOrder::class)]
final readonly class GetItemPriceReferences
{
    public function __construct(
        public string $facilityId,
        public string $supplierId,
        public string $itemId,
    ) {
        foreach ([
            'facilityId' => $facilityId,
            'supplierId' => $supplierId,
            'itemId' => $itemId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("GetItemPriceReferences::\${$name} must be a UUID (IC §3).");
            }
        }
    }
}
