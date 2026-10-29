<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use Carbon\CarbonImmutable;

final readonly class GetFacilityAppointmentCalendarQuery
{
    public function __construct(
        public string $facilityId,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}
}
