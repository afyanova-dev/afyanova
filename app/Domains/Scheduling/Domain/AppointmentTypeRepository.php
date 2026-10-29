<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Domain\Model\AppointmentType;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for AppointmentType aggregate root.
 */
interface AppointmentTypeRepository extends Repository
{
    public function getById(string $id): AppointmentType;

    public function existsByCode(string $code): bool;
}
