<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts;

use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Contracts\Dto\WaitlistEntrySummary;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Published cross-domain contract port for Scheduling & Appointments (Domain 22; ADR-207).
 *
 * Provides cross-domain access to verified scheduling and availability facts without direct DB coupling.
 */
interface SchedulingApi
{
    /**
     * Retrieve appointment summary by ID, or null if not found in current tenant.
     */
    public function getAppointmentSummary(string $appointmentId): ?AppointmentSummary;

    /**
     * Retrieve upcoming active appointments for a patient.
     *
     * @return list<AppointmentSummary>
     */
    public function getPatientUpcomingAppointments(string $patientId, ?string $facilityId = null): array;

    /**
     * Check whether a schedulable resource has open availability in the specified window.
     */
    public function isResourceAvailable(string $resourceId, CarbonImmutable $startTime, CarbonImmutable $endTime): bool;

    /**
     * Retrieve active waitlist entries for a patient.
     *
     * @return list<WaitlistEntrySummary>
     */
    public function getPatientWaitlistEntries(string $patientId, ?string $facilityId = null): array;

    /**
     * Retrieve the complete facility appointment schedule for a date.
     *
     * @return list<AppointmentSummary>
     */
    public function getDailyFacilityAppointments(string $facilityId, CarbonImmutable $date): array;

    /**
     * Display names for appointment types and schedulable resources at a
     * facility, each keyed by id. Boards rendering appointment rows resolve
     * names through here (fixed cost, never per-appointment lookups).
     *
     * @return array{types: array<string, string>, resources: array<string, string>}
     */
    public function getSchedulingDisplayNames(string $facilityId): array;

    /**
     * Record the physical arrival of a patient for a scheduled appointment.
     *
     * @throws AuthorizationException If the caller lacks the scheduling
     *                                arrival-recording permission (the command
     *                                is bus-authorized via AppointmentPolicy)
     * @throws \DomainException If transition is invalid
     */
    public function recordArrival(string $appointmentId): void;

    public function confirmAppointment(string $appointmentId): void;

    public function cancelAppointment(string $appointmentId, ?string $reason = null): void;

    public function rescheduleAppointment(
        string $appointmentId,
        string $newScheduledStart,
        string $newScheduledEnd,
        ?string $newSlotId = null,
    ): void;

    public function markAppointmentNoShow(string $appointmentId): void;
}
