<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Domain\Enum\OrderType;
use App\Platform\Queueing\Domain\Enum\QueuePriority;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Exception\MissingOrderProvenanceException;
use App\Platform\Queueing\Domain\Exception\QueueNotFoundException;
use App\Platform\Queueing\Domain\Exception\QueueNotOperatingException;
use App\Platform\Queueing\Domain\Model\PatientMovement;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\PatientMovementRepository;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Platform\Queueing\Domain\Repository\TicketCounterRepository;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

final readonly class PlaceEncounterInQueueHandler
{
    public function __construct(
        private QueueRepository $queueRepository,
        private QueueEntryRepository $queueEntryRepository,
        private TicketCounterRepository $ticketCounterRepository,
        private PatientMovementRepository $movementRepository,
        private Container $container,
    ) {}

    private function tenantContext(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    public function __invoke(PlaceEncounterInQueueCommand $command): QueueEntryDto
    {
        $queue = $this->queueRepository->findById($command->queueId)
            ?? throw new QueueNotFoundException("Queue not found: {$command->queueId}");

        if ($queue->operating_status->isClosed()) {
            throw QueueNotOperatingException::closed($queue->name);
        }

        if (! $queue->is_active) {
            throw QueueNotOperatingException::inactive($queue->name);
        }

        if ($queue->service_capability !== null) {
            $capability = ServiceCapability::tryFrom($queue->service_capability);
            if ($capability?->requiresOrderProvenance() && ($command->sourceOrderRef === null || trim($command->sourceOrderRef) === '')) {
                throw new MissingOrderProvenanceException(
                    "Queue '{$queue->name}' requires an authoritative source order reference."
                );
            }
        }

        $existing = $command->sourceOrderRef !== null && $command->orderType !== null
            ? $this->queueEntryRepository->findBySourceOrderRef($command->sourceOrderRef, strtoupper($command->orderType))
            : $this->queueEntryRepository->findActiveByEncounterAndQueue($command->encounterId, $command->queueId);

        if ($existing !== null) {
            if ($existing->encounter_id !== $command->encounterId
                || $existing->patient_id !== $command->patientId
                || $existing->queue_id !== $command->queueId) {
                throw new \DomainException('This order has already been routed to a different visit or service queue.');
            }

            return $this->toDto($existing);
        }

        $tenantId = $this->tenantContext()->tenantId();
        $counterDate = now()->toDateString();
        $seq = $this->ticketCounterRepository->nextNumber($queue->facility_id, $queue->code, $counterDate);
        $ticketNumber = sprintf('%s-%03d', $queue->ticket_prefix, $seq);

        $priority = QueuePriority::tryFrom(strtoupper($command->priority)) ?? QueuePriority::ROUTINE;
        $orderType = $command->orderType !== null ? OrderType::tryFrom(strtoupper($command->orderType)) : null;

        $entry = QueueEntry::place(
            tenantId: $tenantId,
            facilityId: $queue->facility_id,
            queueId: $queue->id,
            encounterId: $command->encounterId,
            patientId: $command->patientId,
            ticketNumber: $ticketNumber,
            priority: $priority,
            sourceOrderRef: $command->sourceOrderRef,
            orderType: $orderType,
        );

        $this->queueEntryRepository->save($entry);

        $movement = new PatientMovement([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $queue->facility_id,
            'encounter_id' => $command->encounterId,
            'patient_id' => $command->patientId,
            'from_area' => null,
            'to_area' => $queue->name,
            'status' => 'ARRIVED',
            'queue_entry_id' => $entry->id,
            'reason' => 'Placed in queue',
            'occurred_at' => CarbonImmutable::now(),
        ]);
        $this->movementRepository->save($movement);

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
