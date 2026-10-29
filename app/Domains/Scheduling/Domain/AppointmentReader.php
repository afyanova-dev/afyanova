<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Contracts\Dto\AppointmentDetail;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use Carbon\CarbonImmutable;

/**
 * Read-side query port for Appointments.
 */
interface AppointmentReader
{
    public function getById(string $id): ?AppointmentDetail;

    public function getByNumber(string $appointmentNumber): ?AppointmentDetail;

    /**
     * @return list<AppointmentSummary>
     */
    public function getPatientAppointments(string $patientId, ?string $facilityId = null): array;

    /**
     * @return list<AppointmentSummary>
     */
    public function getResourceAppointments(string $resourceId, ?string $date = null): array;

    /**
     * @return list<AppointmentSummary>
     */
    public function getFacilityAppointments(string $facilityId, CarbonImmutable $date): array;
}
