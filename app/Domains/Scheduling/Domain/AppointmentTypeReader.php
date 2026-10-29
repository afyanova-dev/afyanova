<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Contracts\Dto\AppointmentTypeSummary;

/**
 * Read-side reader port for AppointmentType.
 */
interface AppointmentTypeReader
{
    public function findById(string $id): ?AppointmentTypeSummary;

    /**
     * @return list<AppointmentTypeSummary>
     */
    public function list(?string $facilityId = null): array;
}
