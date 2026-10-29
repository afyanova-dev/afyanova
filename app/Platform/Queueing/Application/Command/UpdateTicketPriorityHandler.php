<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Domain\Enum\QueuePriority;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;

final class UpdateTicketPriorityHandler
{
    public function __construct(
        private readonly QueueEntryRepository $queueEntryRepository,
    ) {}

    public function __invoke(UpdateTicketPriorityCommand $command): ?QueueEntryDto
    {
        $entry = $this->queueEntryRepository->findActiveByEncounter($command->encounterId);

        if ($entry === null) {
            return null;
        }

        $priority = QueuePriority::fromTriagePriority($command->priority);
        $entry->updatePriority($priority);

        $this->queueEntryRepository->save($entry);

        return new QueueEntryDto(
            id: $entry->id,
            tenantId: $entry->tenant_id,
            facilityId: $entry->facility_id,
            queueId: $entry->queue_id,
            encounterId: $entry->encounter_id,
            patientId: $entry->patient_id,
            ticketNumber: $entry->ticket_number,
            priority: $entry->priority->value,
            status: $entry->status->value,
            holdReason: $entry->hold_reason?->value,
            holdProvenance: $entry->hold_provenance,
            clearanceProvenance: $entry->clearance_provenance,
            sourceOrderRef: $entry->source_order_ref,
            orderType: $entry->order_type?->value,
            calledAt: $entry->called_at,
            calledRoomId: $entry->called_room_id,
            calledStaffId: $entry->called_staff_id,
            serviceStartedAt: $entry->service_started_at,
            completedAt: $entry->completed_at,
            callAttempts: $entry->call_attempts,
            createdAt: $entry->created_at,
        );
    }
}
