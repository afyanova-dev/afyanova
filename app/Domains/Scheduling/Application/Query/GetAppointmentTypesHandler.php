<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\AppointmentTypeSummary;
use App\Domains\Scheduling\Domain\AppointmentTypeReader;

final class GetAppointmentTypesHandler
{
    public function __construct(
        private readonly AppointmentTypeReader $reader,
    ) {}

    /**
     * @return list<AppointmentTypeSummary>
     */
    public function __invoke(GetAppointmentTypes $query): array
    {
        return $this->reader->list($query->facilityId);
    }
}
