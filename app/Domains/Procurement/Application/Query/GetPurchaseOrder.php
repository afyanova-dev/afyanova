<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one purchase order + its lines + amendment history + computed order total
 * (ADR-171 D-J). The `view` policy is coarse `procurement.access`; the handler
 * adds an ADR-133 refinement at the PO's facility (the Rad.1 precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: PurchaseOrder::class)]
final readonly class GetPurchaseOrder
{
    public function __construct(public string $purchaseOrderId)
    {
        if (! Str::isUuid($purchaseOrderId)) {
            throw new InvalidArgumentException('GetPurchaseOrder::$purchaseOrderId must be a UUID (IC §3).');
        }
    }
}
