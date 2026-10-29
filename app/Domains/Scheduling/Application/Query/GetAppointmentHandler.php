<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\AppointmentDetail;
use App\Domains\Scheduling\Domain\AppointmentReader;

final class GetAppointmentHandler
{
    public function __construct(
        private readonly AppointmentReader $reader,
    ) {}

    public function __invoke(GetAppointment $query): ?AppointmentDetail
    {
        return $this->reader->getById($query->appointmentId);
    }
}
