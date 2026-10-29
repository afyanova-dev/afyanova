<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\StaffSummary;
use App\Domains\HR\Domain\StaffReader;

final class SearchStaffHandler
{
    public function __construct(private readonly StaffReader $reader) {}

    /**
     * @return list<StaffSummary>
     */
    public function __invoke(SearchStaff $query): array
    {
        return $this->reader->search(
            search: $query->search,
            status: $query->status,
            cadreId: $query->cadreId,
        );
    }
}
