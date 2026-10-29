<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Domain\Exception\QueueEntryNotFoundException;
use App\Platform\Queueing\Domain\Model\PatientMovement;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Port\QueueCallingBroadcasterPort;
use App\Platform\Queueing\Domain\Repository\PatientMovementRepository;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class CallTicketHandler
{
    public function __construct(
        private QueueEntryRepository $queueEntryRepository,
        private QueueRepository $queueRepository,
        private PatientMovementRepository $movementRepository,
        private ?QueueCallingBroadcasterPort $broadcaster = null,
    ) {}

    public function __invoke(CallTicketCommand $command): QueueEntryDto
    {
        $entry = $this->queueEntryRepository->findById($command->entryId)
            ?? throw new QueueEntryNotFoundException("Queue entry not found: {$command->entryId}");

        $entry->call($command->roomId, $command->staffId);

        $this->queueEntryRepository->save($entry);

        $queue = $this->queueRepository->findById($entry->queue_id);

        $movement = new PatientMovement([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $entry->tenant_id,
            'facility_id' => $entry->facility_id,
            'encounter_id' => $entry->encounter_id,
            'patient_id' => $entry->patient_id,
            'from_area' => $queue !== null ? $queue->name : 'WAITING_AREA',
            'to_area' => $command->roomId,
            'status' => 'ARRIVED',
            'queue_entry_id' => $entry->id,
            'actor_id' => $command->staffId,
            'reason' => 'Called to room',
            'occurred_at' => CarbonImmutable::now(),
        ]);
        $this->movementRepository->save($movement);

        if ($this->broadcaster !== null) {
            $chimeType = in_array($entry->priority->value, ['EMERGENCY', 'URGENT'], true) ? 'URGENT' : 'STANDARD';
            $this->broadcaster->broadcastCall(
                tenantId: $entry->tenant_id,
                facilityId: $entry->facility_id,
                queueId: $entry->queue_id,
                ticketNumber: $entry->ticket_number,
                roomId: $command->roomId,
                chimeType: $chimeType,
                status: 'CALLED',
            );
        }

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
