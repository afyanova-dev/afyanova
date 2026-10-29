<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\PendingBillableEventSummary;
use App\Domains\Billing\Domain\ChargeReader;

final class GetPendingBillableEventsHandler
{
    public function __construct(private readonly ChargeReader $reader) {}

    /**
     * @return list<PendingBillableEventSummary>
     */
    public function __invoke(GetPendingBillableEvents $query): array
    {
        return $this->reader->pendingBillableEvents($query->facilityId);
    }
}
