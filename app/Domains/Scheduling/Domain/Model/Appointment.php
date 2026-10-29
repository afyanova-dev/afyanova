<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Model;

use App\Domains\Scheduling\Contracts\Event\AppointmentArrived;
use App\Domains\Scheduling\Contracts\Event\AppointmentBooked;
use App\Domains\Scheduling\Contracts\Event\AppointmentCancelled;
use App\Domains\Scheduling\Contracts\Event\AppointmentConfirmed;
use App\Domains\Scheduling\Contracts\Event\AppointmentNoShowRecorded;
use App\Domains\Scheduling\Contracts\Event\AppointmentRescheduled;
use App\Domains\Scheduling\Domain\Exception\InvalidAppointmentStatusTransitionException;
use App\Domains\Scheduling\Domain\ValueObject\AppointmentStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Appointment aggregate root (22-scheduling-and-appointments-domain.md §46–§60, ADR-207).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $appointment_number
 * @property string|null $slot_id
 * @property string $patient_id
 * @property string $resource_id
 * @property string $appointment_type_id
 * @property CarbonImmutable $scheduled_start
 * @property CarbonImmutable $scheduled_end
 * @property AppointmentStatus $status
 * @property string|null $reason
 * @property string|null $cancellation_reason
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $arrived_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Appointment extends AggregateRoot
{
    protected $table = 'sch_appointments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'appointment_number',
        'slot_id',
        'patient_id',
        'resource_id',
        'appointment_type_id',
        'scheduled_start',
        'scheduled_end',
        'status',
        'reason',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'arrived_at',
        'started_at',
        'completed_at',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'scheduled_start' => 'immutable_datetime',
        'scheduled_end' => 'immutable_datetime',
        'status' => AppointmentStatus::class,
        'cancelled_at' => 'immutable_datetime',
        'arrived_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function book(
        string $tenantId,
        string $facilityId,
        string $appointmentNumber,
        string $patientId,
        string $resourceId,
        string $appointmentTypeId,
        CarbonImmutable $scheduledStart,
        CarbonImmutable $scheduledEnd,
        ?string $slotId = null,
        ?string $reason = null,
        ?string $appointmentId = null,
    ): self {
        $id = $appointmentId ?? (string) Str::uuid();

        $appointment = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'appointment_number' => $appointmentNumber,
            'slot_id' => $slotId,
            'patient_id' => $patientId,
            'resource_id' => $resourceId,
            'appointment_type_id' => $appointmentTypeId,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'status' => AppointmentStatus::BOOKED,
            'reason' => $reason,
            'version' => 0,
        ]);

        $appointment->id = $id;

        $appointment->recordThat(new AppointmentBooked(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            appointmentId: $id,
            appointmentNumber: $appointmentNumber,
            slotId: $slotId,
            patientId: $patientId,
            resourceId: $resourceId,
            appointmentTypeId: $appointmentTypeId,
            scheduledStart: $scheduledStart->toIso8601String(),
            scheduledEnd: $scheduledEnd->toIso8601String(),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $appointment;
    }

    public function confirm(): void
    {
        if (! $this->status->canBeConfirmed()) {
            throw InvalidAppointmentStatusTransitionException::forTransition(
                $this->id,
                $this->status->value,
                'confirm'
            );
        }

        $this->status = AppointmentStatus::CONFIRMED;

        $this->recordThat(new AppointmentConfirmed(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            appointmentId: $this->id,
            appointmentNumber: $this->appointment_number,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function recordArrival(?CarbonImmutable $arrivedAt = null): void
    {
        if (! $this->status->canRecordArrival()) {
            throw InvalidAppointmentStatusTransitionException::forTransition(
                $this->id,
                $this->status->value,
                'record_arrival'
            );
        }

        $arrival = $arrivedAt ?? CarbonImmutable::now();
        $this->status = AppointmentStatus::ARRIVED;
        $this->arrived_at = $arrival;

        $this->recordThat(new AppointmentArrived(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            appointmentId: $this->id,
            appointmentNumber: $this->appointment_number,
            patientId: $this->patient_id,
            arrivedAt: $arrival->toIso8601String(),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function startService(?CarbonImmutable $startedAt = null): void
    {
        if (! $this->status->canStartService()) {
            throw InvalidAppointmentStatusTransitionException::forTransition(
                $this->id,
                $this->status->value,
                'start_service'
            );
        }

        $this->status = AppointmentStatus::IN_SERVICE;
        $this->started_at = $startedAt ?? CarbonImmutable::now();
    }

    public function complete(?CarbonImmutable $completedAt = null): void
    {
        if (! $this->status->canComplete()) {
            throw InvalidAppointmentStatusTransitionException::forTransition(
                $this->id,
                $this->status->value,
                'complete'
            );
        }

        $this->status = AppointmentStatus::COMPLETED;
        $this->completed_at = $completedAt ?? CarbonImmutable::now();
    }

    public function reschedule(
        ?string $newSlotId,
        CarbonImmutable $newStart,
        CarbonImmutable $newEnd,
    ): void {
        if (! $this->status->canReschedule()) {
            throw InvalidAppointmentStatusTransitionException::forTransition(
                $this->id,
                $this->status->value,
                'reschedule'
            );
        }

        $oldSlotId = $this->slot_id;
        $oldStart = $this->scheduled_start;

        $this->slot_id = $newSlotId;
        $this->scheduled_start = $newStart;
        $this->scheduled_end = $newEnd;
        $this->status = AppointmentStatus::BOOKED;

        $this->recordThat(new AppointmentRescheduled(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            appointmentId: $this->id,
            appointmentNumber: $this->appointment_number,
            oldSlotId: $oldSlotId,
            newSlotId: $newSlotId,
            oldScheduledStart: $oldStart->toIso8601String(),
            newScheduledStart: $newStart->toIso8601String(),
            newScheduledEnd: $newEnd->toIso8601String(),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function cancel(?string $reason = null, ?string $cancelledBy = null): void
    {
        if (! $this->status->canCancel()) {
            throw InvalidAppointmentStatusTransitionException::forTransition(
                $this->id,
                $this->status->value,
                'cancel'
            );
        }

        $this->status = AppointmentStatus::CANCELLED;
        $this->cancellation_reason = $reason;
        $this->cancelled_by = $cancelledBy;
        $this->cancelled_at = CarbonImmutable::now();

        $this->recordThat(new AppointmentCancelled(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            appointmentId: $this->id,
            appointmentNumber: $this->appointment_number,
            slotId: $this->slot_id,
            reason: $reason,
            cancelledBy: $cancelledBy,
            cancelledAt: $this->cancelled_at->toIso8601String(),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function markNoShow(): void
    {
        if (! $this->status->canMarkNoShow()) {
            throw InvalidAppointmentStatusTransitionException::forTransition(
                $this->id,
                $this->status->value,
                'mark_no_show'
            );
        }

        $this->status = AppointmentStatus::NO_SHOW;

        $this->recordThat(new AppointmentNoShowRecorded(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            appointmentId: $this->id,
            appointmentNumber: $this->appointment_number,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }
}
