<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one purchase requisition + its lines (ADR-171 D-J). The `view` policy is
 * the coarse `procurement.access` gate; the handler adds an ADR-133 refinement
 * at the requisition's own facility (the Rad.1 `GetImagingOrder` precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: PurchaseRequisition::class)]
final readonly class GetPurchaseRequisition
{
    public function __construct(
        public string $requisitionId,
        public string $facilityId,
    ) {
        foreach (['requisitionId' => $requisitionId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("GetPurchaseRequisition::\${$name} must be a UUID (IC §3).");
            }
        }
    }
}
