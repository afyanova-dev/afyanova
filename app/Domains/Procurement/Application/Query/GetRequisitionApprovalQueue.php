<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The SUBMITTED purchase requisitions at a facility — the approver worklist
 * (§307; ADR-171 D-J). Facility-scoped (ADR-133 refinement in the handler).
 */
#[ProtectedQuery(ability: 'view', subjectType: PurchaseRequisition::class)]
final readonly class GetRequisitionApprovalQueue
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetRequisitionApprovalQueue::$facilityId must be a UUID (IC §3).');
        }
    }
}
