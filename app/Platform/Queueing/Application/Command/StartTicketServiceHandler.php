<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Domain\Exception\QueueEntryNotFoundException;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;

final class StartTicketServiceHandler
{
    public function __construct(
        private readonly QueueEntryRepository $queueEntryRepository,
    ) {}

    public function __invoke(StartTicketServiceCommand $command): QueueEntryDto
    {
        $entry = $this->queueEntryRepository->findById($command->entryId)
            ?? throw new QueueEntryNotFoundException("Queue entry not found: {$command->entryId}");

        $entry->startService();

        $this->queueEntryRepository->save($entry);

        return $this->toDto($entry);
    }

    private function toDto(QueueEntry $entry): QueueEntryDto
    {
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
