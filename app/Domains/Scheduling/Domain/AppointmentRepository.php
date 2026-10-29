<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side repository port for Appointment aggregate root.
 */
interface AppointmentRepository extends Repository
{
    public function getById(string $id): Appointment;

    public function getByIdForUpdate(string $id): Appointment;

    public function existsByNumber(string $appointmentNumber): bool;

    /**
     * True when a non-terminal appointment on the resource overlaps
     * [start, end). Terminal states (CANCELLED/RESCHEDULED/NO_SHOW) free the
     * interval — same exclusion set as the scheduling API availability check.
     */
    public function existsOverlapping(string $resourceId, CarbonImmutable $start, CarbonImmutable $end): bool;

    /**
     * Overlap check that ignores one appointment — rescheduling within (or
     * around) its own current interval must not conflict with itself.
     * Same terminal-state exclusions as existsOverlapping.
     */
    public function existsOverlappingExcluding(
        string $resourceId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $excludeAppointmentId,
    ): bool;
}
