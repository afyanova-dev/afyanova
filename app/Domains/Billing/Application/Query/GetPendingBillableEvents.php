<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\BillableEvent;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The captured billable events awaiting a charge — the revenue-leakage worklist
 * (20-billing-and-payments-domain.md §130 / §131; ADR-189 D-G, sponsor ruling
 * C1). Optional facility filter. `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: BillableEvent::class)]
final readonly class GetPendingBillableEvents
{
    public function __construct(public ?string $facilityId = null)
    {
        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetPendingBillableEvents::$facilityId must be a UUID or null (IC §3).');
        }
    }
}
