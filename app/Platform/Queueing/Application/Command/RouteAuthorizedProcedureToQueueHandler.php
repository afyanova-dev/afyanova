<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Domain\Enum\OrderType;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RouteAuthorizedProcedureToQueueHandler
{
    public function __construct(
        private readonly PlaceEncounterInQueueHandler $placer,
        private readonly QueueEntryRepository $entries,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RouteAuthorizedProcedureToQueue $command): void
    {
        $tenantId = $this->context->tenantId();
        $existing = $this->entries->findBySourceOrderRef($command->procedureId, OrderType::PROCEDURE_BOOKING->value);
        if ($existing !== null) {
            if ($existing->facility_id !== $command->facilityId
                || $existing->patient_id !== $command->patientId
                || $existing->encounter_id !== $command->encounterId) {
                throw new RuntimeException('The procedure order is already routed to a different patient visit.');
            }

            return;
        }

        $mapping = DB::table('qms_procedure_queue_mappings')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $command->facilityId)
            ->where('procedure_definition_id', $command->definitionId)
            ->first();

        if ($mapping === null) {
            throw new RuntimeException(sprintf(
                'No procedure queue is configured for definition %s at facility %s.',
                $command->definitionId,
                $command->facilityId,
            ));
        }

        $queue = Queue::query()->where('facility_id', $command->facilityId)->find($mapping->queue_id);
        if ($queue === null
            || $queue->service_capability !== ServiceCapability::PROCEDURE->value
            || ! $queue->is_active
            || $queue->operating_status->isClosed()) {
            throw new RuntimeException(sprintf(
                'The configured procedure queue for definition %s is unavailable.',
                $command->definitionId,
            ));
        }

        $hasActiveRoom = DB::table('qms_queue_rooms')
            ->join('org_rooms', 'org_rooms.id', '=', 'qms_queue_rooms.room_id')
            ->where('qms_queue_rooms.facility_id', $command->facilityId)
            ->where('qms_queue_rooms.queue_id', $queue->id)
            ->where('org_rooms.status', 'ACTIVE')
            ->exists();
        if (! $hasActiveRoom) {
            throw new RuntimeException(sprintf(
                'The configured procedure queue for definition %s has no active service room.',
                $command->definitionId,
            ));
        }

        $this->placer->__invoke(new PlaceEncounterInQueueCommand(
            encounterId: $command->encounterId,
            patientId: $command->patientId,
            queueId: (string) $queue->id,
            sourceOrderRef: $command->procedureId,
            orderType: OrderType::PROCEDURE_BOOKING->value,
        ));
    }
}
