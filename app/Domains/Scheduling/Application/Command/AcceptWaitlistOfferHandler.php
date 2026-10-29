<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentRepository;
use App\Domains\Scheduling\Domain\Exception\InvalidWaitlistTransitionException;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Domains\Scheduling\Domain\SlotRepository;
use App\Domains\Scheduling\Domain\WaitlistEntryRepository;
use App\Domains\Scheduling\Infrastructure\Sequence\AppointmentNumberSequence;

final readonly class AcceptWaitlistOfferHandler
{
    public function __construct(
        private WaitlistEntryRepository $repository,
        private SlotRepository $slotRepository,
        private AppointmentRepository $appointmentRepository,
        private AppointmentNumberSequence $appointmentNumberSequence,
    ) {}

    public function __invoke(AcceptWaitlistOffer $command): string
    {
        $entry = $this->repository->getByIdForUpdate($command->waitlistEntryId);

        if ($entry->offered_slot_id === null) {
            throw InvalidWaitlistTransitionException::from($entry->status->value, 'acceptOffer');
        }

        $slot = $this->slotRepository->getByIdForUpdate((string) $entry->offered_slot_id);
        $slot->book();

        $appointmentNumber = $this->appointmentNumberSequence->next((string) $entry->tenant_id);

        $appointment = Appointment::book(
            tenantId: (string) $entry->tenant_id,
            facilityId: (string) $entry->facility_id,
            appointmentNumber: $appointmentNumber,
            patientId: (string) $entry->patient_id,
            resourceId: (string) $slot->resource_id,
            appointmentTypeId: (string) $entry->appointment_type_id,
            scheduledStart: $slot->start_time,
            scheduledEnd: $slot->end_time,
            slotId: (string) $slot->id,
            reason: $entry->notes,
            appointmentId: $command->appointmentId,
        );

        $this->appointmentRepository->save($appointment);

        $entry->acceptOffer((string) $appointment->id);

        $this->slotRepository->save($slot);
        $this->repository->save($entry);

        return (string) $appointment->id;
    }
}
