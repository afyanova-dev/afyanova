<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\CashSessionSummary;
use App\Domains\Billing\Domain\CashSessionReader;

final class GetFacilityCashSessionsHandler
{
    public function __construct(private readonly CashSessionReader $reader) {}

    /**
     * @return list<CashSessionSummary>
     */
    public function __invoke(GetFacilityCashSessions $query): array
    {
        return $this->reader->forFacility($query->facilityId, $query->status);
    }
}
