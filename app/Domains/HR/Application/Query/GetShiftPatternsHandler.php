<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\ShiftPatternSummary;
use App\Domains\HR\Domain\ShiftPatternReader;

final class GetShiftPatternsHandler
{
    public function __construct(private readonly ShiftPatternReader $reader) {}

    /**
     * @return list<ShiftPatternSummary>
     */
    public function __invoke(GetShiftPatterns $query): array
    {
        return $this->reader->listByFacility($query->facilityId, $query->activeOnly);
    }
}
