<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Domain\Exception\QueueNotOperatingException;
use App\Platform\Queueing\Domain\Model\PatientMovement;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Port\QueueCallingBroadcasterPort;
use App\Platform\Queueing\Domain\Repository\PatientMovementRepository;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class CallNextTicketHandler
{
    public function __construct(
        private QueueEntryRepository $queueEntryRepository,
        private QueueRepository $queueRepository,
        private PatientMovementRepository $movementRepository,
        private ?QueueCallingBroadcasterPort $broadcaster = null,
    ) {}

    public function __invoke(CallNextTicketCommand $command): ?QueueEntryDto
    {
        $queue = $this->queueRepository->findById($command->queueId);
        if ($queue !== null) {
            if ($queue->operating_status->isPaused()) {
                throw QueueNotOperatingException::paused($queue->name, $queue->pause_reason);
            }
            if ($queue->operating_status->isClosed()) {
                throw QueueNotOperatingException::closed($queue->name);
            }
        }

        $entry = $this->queueEntryRepository->findNextCallable($command->queueId);

        if ($entry === null) {
            return null;
        }

        $entry->call($command->roomId, $command->staffId);

        $this->queueEntryRepository->save($entry);

        $fromArea = $queue !== null ? $queue->name : 'WAITING_AREA';

        $movement = new PatientMovement([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $entry->tenant_id,
            'facility_id' => $entry->facility_id,
            'encounter_id' => $entry->encounter_id,
            'patient_id' => $entry->patient_id,
            'from_area' => $fromArea,
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
