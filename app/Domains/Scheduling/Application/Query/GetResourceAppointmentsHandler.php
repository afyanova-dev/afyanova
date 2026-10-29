<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Domain\AppointmentReader;

final class GetResourceAppointmentsHandler
{
    public function __construct(
        private readonly AppointmentReader $reader,
    ) {}

    /**
     * @return list<AppointmentSummary>
     */
    public function __invoke(GetResourceAppointments $query): array
    {
        return $this->reader->getResourceAppointments($query->resourceId, $query->date);
    }
}
