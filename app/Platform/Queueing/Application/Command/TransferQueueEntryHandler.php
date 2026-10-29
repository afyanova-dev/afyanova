<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Domain\Enum\OrderType;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Exception\MissingOrderProvenanceException;
use App\Platform\Queueing\Domain\Exception\QueueEntryNotFoundException;
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

final readonly class TransferQueueEntryHandler
{
    public function __construct(
        private QueueEntryRepository $queueEntryRepository,
        private QueueRepository $queueRepository,
        private TicketCounterRepository $ticketCounterRepository,
        private PatientMovementRepository $movementRepository,
        private Container $container,
    ) {}

    private function tenantContext(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    public function __invoke(TransferQueueEntryCommand $command): QueueEntryDto
    {
        $sourceEntry = $this->queueEntryRepository->findById($command->entryId)
            ?? throw new QueueEntryNotFoundException("Source queue entry not found: {$command->entryId}");

        $destQueue = $this->queueRepository->findById($command->destinationQueueId)
            ?? throw new QueueNotFoundException("Destination queue not found: {$command->destinationQueueId}");

        if ($destQueue->facility_id !== $sourceEntry->facility_id) {
            throw new QueueNotFoundException("Destination queue not found: {$command->destinationQueueId}");
        }

        if ($destQueue->operating_status->isClosed()) {
            throw QueueNotOperatingException::closed($destQueue->name);
        }

        $sourceOrderRef = $command->sourceOrderRef ?? $sourceEntry->source_order_ref;
        $orderType = $command->orderType !== null
            ? OrderType::tryFrom(strtoupper($command->orderType))
            : $sourceEntry->order_type;

        if ($destQueue->service_capability !== null) {
            $capability = ServiceCapability::tryFrom($destQueue->service_capability);
            if ($capability?->requiresOrderProvenance() && ($sourceOrderRef === null || trim($sourceOrderRef) === '')) {
                throw new MissingOrderProvenanceException(
                    "Destination queue '{$destQueue->name}' requires an authoritative source order reference."
                );
            }
        }

        $sourceQueue = $this->queueRepository->findById($sourceEntry->queue_id);

        $tenantId = $this->tenantContext()->tenantId();
        $counterDate = now()->toDateString();
        $seq = $this->ticketCounterRepository->nextNumber($destQueue->facility_id, $destQueue->code, $counterDate);
        $ticketNumber = sprintf('%s-%03d', $destQueue->ticket_prefix, $seq);

        $newEntry = QueueEntry::place(
            tenantId: $tenantId,
            facilityId: $destQueue->facility_id,
            queueId: $destQueue->id,
            encounterId: $sourceEntry->encounter_id,
            patientId: $sourceEntry->patient_id,
            ticketNumber: $ticketNumber,
            priority: $sourceEntry->priority,
            sourceOrderRef: $sourceOrderRef,
            orderType: $orderType,
        );

        $sourceEntry->recordTransferTo($newEntry->id, $destQueue->id, $command->reason);

        $this->queueEntryRepository->save($sourceEntry);
        $this->queueEntryRepository->save($newEntry);

        $fromArea = $sourceQueue !== null ? $sourceQueue->name : 'QUEUE';

        $movement = new PatientMovement([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $destQueue->facility_id,
            'encounter_id' => $sourceEntry->encounter_id,
            'patient_id' => $sourceEntry->patient_id,
            'from_area' => $fromArea,
            'to_area' => $destQueue->name,
            'status' => 'ARRIVED',
            'queue_entry_id' => $newEntry->id,
            'reason' => $command->reason ?? 'Transferred queue',
            'occurred_at' => CarbonImmutable::now(),
        ]);
        $this->movementRepository->save($movement);

        return $this->toDto($newEntry);
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
