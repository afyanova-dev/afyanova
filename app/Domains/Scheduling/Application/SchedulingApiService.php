<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application;

use App\Domains\Scheduling\Application\Command\CancelAppointment;
use App\Domains\Scheduling\Application\Command\ConfirmAppointment;
use App\Domains\Scheduling\Application\Command\MarkAppointmentNoShow;
use App\Domains\Scheduling\Application\Command\RecordAppointmentArrival;
use App\Domains\Scheduling\Application\Command\RescheduleAppointment;
use App\Domains\Scheduling\Application\Query\GetAppointmentTypes;
use App\Domains\Scheduling\Application\Query\GetDailyFacilityAppointments;
use App\Domains\Scheduling\Application\Query\GetSchedulableResources;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Contracts\Dto\WaitlistEntrySummary;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Domains\Scheduling\Domain\AppointmentReader;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Domains\Scheduling\Domain\WaitlistEntryReader;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Carbon\CarbonImmutable;

final readonly class SchedulingApiService implements SchedulingApi
{
    public function __construct(
        private AppointmentReader $appointmentReader,
        private WaitlistEntryReader $waitlistEntryReader,
        private QueryBus $queryBus,
        private CommandBus $commandBus,
    ) {}

    public function getAppointmentSummary(string $appointmentId): ?AppointmentSummary
    {
        $detail = $this->appointmentReader->getById($appointmentId);
        if ($detail === null) {
            return null;
        }

        return new AppointmentSummary(
            id: $detail->id,
            facilityId: $detail->facilityId,
            appointmentNumber: $detail->appointmentNumber,
            slotId: $detail->slotId,
            patientId: $detail->patientId,
            resourceId: $detail->resourceId,
            appointmentTypeId: $detail->appointmentTypeId,
            scheduledStart: $detail->scheduledStart,
            scheduledEnd: $detail->scheduledEnd,
            status: $detail->status,
            reason: $detail->reason,
        );
    }

    /**
     * @return list<AppointmentSummary>
     */
    public function getPatientUpcomingAppointments(string $patientId, ?string $facilityId = null): array
    {
        $appointments = $this->appointmentReader->getPatientAppointments($patientId, $facilityId);
        $now = CarbonImmutable::now()->toIso8601String();

        return array_values(array_filter(
            $appointments,
            fn (AppointmentSummary $a): bool => $a->scheduledEnd >= $now
                && in_array($a->status, ['BOOKED', 'CONFIRMED', 'ARRIVED', 'IN_SERVICE'], true)
        ));
    }

    public function isResourceAvailable(string $resourceId, CarbonImmutable $startTime, CarbonImmutable $endTime): bool
    {
        $hasConflict = Appointment::query()
            ->where('resource_id', $resourceId)
            ->whereNotIn('status', ['CANCELLED', 'RESCHEDULED', 'NO_SHOW'])
            ->where('scheduled_start', '<', $endTime)
            ->where('scheduled_end', '>', $startTime)
            ->exists();

        return ! $hasConflict;
    }

    /**
     * @return list<WaitlistEntrySummary>
     */
    public function getPatientWaitlistEntries(string $patientId, ?string $facilityId = null): array
    {
        return $this->waitlistEntryReader->getPatientWaitlist($patientId, $facilityId);
    }

    /**
     * @return list<AppointmentSummary>
     */
    public function getDailyFacilityAppointments(string $facilityId, CarbonImmutable $date): array
    {
        return $this->queryBus->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityId,
            date: $date,
        ));
    }

    /**
     * @return array{types: array<string, string>, resources: array<string, string>}
     */
    public function getSchedulingDisplayNames(string $facilityId): array
    {
        $types = [];
        $resources = [];
        foreach ($this->queryBus->dispatch(new GetAppointmentTypes($facilityId)) as $type) {
            $types[$type->id] = $type->name;
        }
        foreach ($this->queryBus->dispatch(new GetSchedulableResources($facilityId)) as $resource) {
            $resources[$resource->id] = $resource->name;
        }

        return ['types' => $types, 'resources' => $resources];
    }

    public function recordArrival(string $appointmentId): void
    {
        $this->commandBus->dispatch(new RecordAppointmentArrival($appointmentId));
    }

    public function confirmAppointment(string $appointmentId): void
    {
        $this->commandBus->dispatch(new ConfirmAppointment($appointmentId));
    }

    public function cancelAppointment(string $appointmentId, ?string $reason = null): void
    {
        $this->commandBus->dispatch(new CancelAppointment(
            appointmentId: $appointmentId,
            reason: $reason,
        ));
    }

    public function rescheduleAppointment(
        string $appointmentId,
        string $newScheduledStart,
        string $newScheduledEnd,
        ?string $newSlotId = null,
    ): void {
        $this->commandBus->dispatch(new RescheduleAppointment(
            appointmentId: $appointmentId,
            newScheduledStart: $newScheduledStart,
            newScheduledEnd: $newScheduledEnd,
            newSlotId: $newSlotId,
        ));
    }

    public function markAppointmentNoShow(string $appointmentId): void
    {
        $this->commandBus->dispatch(new MarkAppointmentNoShow($appointmentId));
    }
}
