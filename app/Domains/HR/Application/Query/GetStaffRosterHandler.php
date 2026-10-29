<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\RosterEntrySummary;
use App\Domains\HR\Domain\RosterEntryReader;

final class GetStaffRosterHandler
{
    public function __construct(private readonly RosterEntryReader $reader) {}

    /**
     * @return list<RosterEntrySummary>
     */
    public function __invoke(GetStaffRoster $query): array
    {
        return $this->reader->forStaff(
            staffId: $query->staffId,
            startDate: $query->startDate,
            endDate: $query->endDate,
        );
    }
}
