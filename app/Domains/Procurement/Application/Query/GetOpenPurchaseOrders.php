<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The ISSUED purchase orders at a facility — awaiting delivery (§86 / §307;
 * ADR-171 D-J). Proc.1b widens the "open" set once the receipt tail exists.
 * Facility-scoped (ADR-133 refinement in the handler).
 */
#[ProtectedQuery(ability: 'view', subjectType: PurchaseOrder::class)]
final readonly class GetOpenPurchaseOrders
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetOpenPurchaseOrders::$facilityId must be a UUID (IC §3).');
        }
    }
}
