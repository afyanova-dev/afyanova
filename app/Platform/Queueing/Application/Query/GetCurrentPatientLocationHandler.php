<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\OperationalLocationDto;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\PatientMovementRepository;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use Carbon\CarbonImmutable;

final readonly class GetCurrentPatientLocationHandler
{
    public function __construct(
        private QueueEntryRepository $queueEntryRepository,
        private PatientMovementRepository $movementRepository,
        private QueueRepository $queueRepository,
    ) {}

    public function __invoke(GetCurrentPatientLocationQuery $query): ?OperationalLocationDto
    {
        $activeEntry = $this->queueEntryRepository->findActiveByEncounter($query->encounterId);
        $latestMovement = $this->movementRepository->findLatestForEncounter($query->encounterId);
        $now = CarbonImmutable::now();

        if ($activeEntry === null && $latestMovement === null) {
            return null;
        }

        if ($latestMovement !== null) {
            $currentWorkArea = $latestMovement->to_area;
            $fromArea = $latestMovement->from_area;
            $reason = $latestMovement->reason;
            $enteredAt = $latestMovement->occurred_at;
            $patientId = $latestMovement->patient_id;
            $currentState = $activeEntry !== null ? $activeEntry->status->value : $latestMovement->status;
            $currentQueueEntryId = $activeEntry !== null ? $activeEntry->id : $latestMovement->queue_entry_id;
            $currentTicketNumber = $activeEntry?->ticket_number;
            $currentRoomId = $activeEntry?->called_room_id;
            $currentStaffId = $activeEntry !== null ? $activeEntry->called_staff_id : $latestMovement->actor_id;
        } else {
            /** @var QueueEntry $activeEntry */
            $queue = $this->queueRepository->findById($activeEntry->queue_id);
            $currentWorkArea = $activeEntry->called_room_id ?? ($queue !== null ? $queue->name : 'QUEUE');
            $fromArea = null;
            $reason = null;
            $enteredAt = $activeEntry->called_at ?? $activeEntry->created_at;
            $patientId = $activeEntry->patient_id;
            $currentState = $activeEntry->status->value;
            $currentQueueEntryId = $activeEntry->id;
            $currentTicketNumber = $activeEntry->ticket_number;
            $currentRoomId = $activeEntry->called_room_id;
            $currentStaffId = $activeEntry->called_staff_id;
        }

        return new OperationalLocationDto(
            encounterId: $query->encounterId,
            patientId: $patientId,
            currentWorkArea: $currentWorkArea,
            currentState: $currentState,
            fromArea: $fromArea,
            reason: $reason,
            currentQueueEntryId: $currentQueueEntryId,
            currentTicketNumber: $currentTicketNumber,
            currentRoomId: $currentRoomId,
            currentStaffId: $currentStaffId,
            enteredAt: $enteredAt,
            asOf: $now,
        );
    }
}
