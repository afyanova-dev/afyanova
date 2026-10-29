<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Domain\Model\SchedulableResource;
use App\Domains\Scheduling\Domain\ValueObject\ResourceType;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for SchedulableResource aggregate root.
 */
interface SchedulableResourceRepository extends Repository
{
    public function getById(string $id): SchedulableResource;

    /**
     * Row-locked read: serializes concurrent bookings against the same
     * resource inside the request transaction (BookAppointmentHandler).
     */
    public function getByIdForUpdate(string $id): SchedulableResource;

    public function existsForRef(string $facilityId, ResourceType $type, string $resourceRef): bool;
}
