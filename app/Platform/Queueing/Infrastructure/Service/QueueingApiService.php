<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Service;

use App\Platform\Queueing\Application\Command\CallNextTicketCommand;
use App\Platform\Queueing\Application\Command\CallTicketCommand;
use App\Platform\Queueing\Application\Command\CancelEncounterQueueEntriesCommand;
use App\Platform\Queueing\Application\Command\CloseQueueCommand;
use App\Platform\Queueing\Application\Command\CompleteTicketServiceCommand;
use App\Platform\Queueing\Application\Command\PauseQueueCommand;
use App\Platform\Queueing\Application\Command\PlaceEncounterInQueueCommand;
use App\Platform\Queueing\Application\Command\PlaceQueueEntryOnHoldCommand;
use App\Platform\Queueing\Application\Command\RecallTicketCommand;
use App\Platform\Queueing\Application\Command\RecordLeftWithoutBeingSeenCommand;
use App\Platform\Queueing\Application\Command\RecordTicketNoResponseCommand;
use App\Platform\Queueing\Application\Command\ReleaseQueueEntryHoldCommand;
use App\Platform\Queueing\Application\Command\ResumeQueueCommand;
use App\Platform\Queueing\Application\Command\SkipTicketCommand;
use App\Platform\Queueing\Application\Command\StartTicketServiceCommand;
use App\Platform\Queueing\Application\Command\TransferQueueEntryCommand;
use App\Platform\Queueing\Application\Command\UpdateTicketPriorityCommand;
use App\Platform\Queueing\Application\Query\GetCurrentPatientLocationQuery;
use App\Platform\Queueing\Application\Query\GetEncounterActiveQueueEntryQuery;
use App\Platform\Queueing\Application\Query\GetFacilityWaitingEntriesQuery;
use App\Platform\Queueing\Application\Query\GetOpenTicketEntries;
use App\Platform\Queueing\Application\Query\GetPatientMovementsQuery;
use App\Platform\Queueing\Application\Query\GetQueueCallingBoardQuery;
use App\Platform\Queueing\Application\Query\GetQueueEntryQuery;
use App\Platform\Queueing\Application\Query\GetQueuePositionQuery;
use App\Platform\Queueing\Application\Query\GetQueueQuery;
use App\Platform\Queueing\Application\Query\GetQueueWaitMetricsQuery;
use App\Platform\Queueing\Application\Query\ListFacilityQueuesQuery;
use App\Platform\Queueing\Contracts\DTO\CallingBoardDto;
use App\Platform\Queueing\Contracts\DTO\CallNextDto;
use App\Platform\Queueing\Contracts\DTO\CallTicketDto;
use App\Platform\Queueing\Contracts\DTO\OperationalLocationDto;
use App\Platform\Queueing\Contracts\DTO\PatientMovementDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\DTO\QueueDto;
use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Contracts\DTO\QueueWaitMetricsDto;
use App\Platform\Queueing\Contracts\DTO\RecallTicketDto;
use App\Platform\Queueing\Contracts\DTO\TransferQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final readonly class QueueingApiService implements QueueingApi
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private TenantContext $context,
    ) {}

    public function place(PlaceInQueueDto $dto): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new PlaceEncounterInQueueCommand(
            encounterId: $dto->encounterId,
            patientId: $dto->patientId,
            queueId: $dto->queueId,
            priority: $dto->priority,
            sourceOrderRef: $dto->sourceOrderRef,
            orderType: $dto->orderType,
        ));
    }

    public function callNext(CallNextDto $dto): ?QueueEntryDto
    {
        /** @var QueueEntryDto|null */
        return $this->commandBus->dispatch(new CallNextTicketCommand(
            queueId: $dto->queueId,
            roomId: $dto->roomId,
            staffId: $dto->staffId,
        ));
    }

    public function call(CallTicketDto $dto): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new CallTicketCommand(
            entryId: $dto->entryId,
            roomId: $dto->roomId,
            staffId: $dto->staffId,
        ));
    }

    public function updatePriority(string $entryId, string $priority, string $provenance): ?QueueEntryDto
    {
        /** @var QueueEntryDto|null */
        $entry = $this->queryBus->dispatch(new GetQueueEntryQuery($entryId));

        if ($entry === null) {
            return null;
        }

        /** @var QueueEntryDto|null */
        return $this->commandBus->dispatch(new UpdateTicketPriorityCommand(
            encounterId: $entry->encounterId,
            priority: $priority,
            provenance: $provenance,
        ));
    }

    public function listWaitingEntries(string $facilityId, int $limit = 200): array
    {
        /** @var list<QueueEntryDto> */
        return $this->queryBus->dispatch(new GetFacilityWaitingEntriesQuery($facilityId, $limit));
    }

    public function getOpenTicketEntries(string $facilityId, int $limit = 200): array
    {
        /** @var array{waiting: list<QueueEntryDto>, called: list<QueueEntryDto>, inService: list<QueueEntryDto>, onHold: list<QueueEntryDto>} */
        return $this->queryBus->dispatch(new GetOpenTicketEntries($facilityId, $limit));
    }

    public function recall(RecallTicketDto $dto): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new RecallTicketCommand(
            entryId: $dto->entryId,
            roomId: $dto->roomId,
            staffId: $dto->staffId,
        ));
    }

    public function startService(string $entryId): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new StartTicketServiceCommand($entryId));
    }

    public function completeService(string $entryId): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new CompleteTicketServiceCommand($entryId));
    }

    public function placeHold(string $entryId, string $holdReason, string $holdProvenance): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new PlaceQueueEntryOnHoldCommand(
            entryId: $entryId,
            holdReason: $holdReason,
            holdProvenance: $holdProvenance,
        ));
    }

    public function releaseHold(string $entryId, string $clearanceProvenance): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new ReleaseQueueEntryHoldCommand(
            entryId: $entryId,
            clearanceProvenance: $clearanceProvenance,
        ));
    }

    public function recordNoResponse(string $entryId): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new RecordTicketNoResponseCommand($entryId));
    }

    public function recordLeftWithoutBeingSeen(string $entryId, ?string $reason = null): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new RecordLeftWithoutBeingSeenCommand($entryId, $reason));
    }

    public function skip(string $entryId, ?string $reason = null): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new SkipTicketCommand($entryId, $reason));
    }

    public function pauseQueue(string $queueId, string $reason): QueueDto
    {
        /** @var QueueDto */
        return $this->commandBus->dispatch(new PauseQueueCommand($queueId, $reason));
    }

    public function resumeQueue(string $queueId): QueueDto
    {
        /** @var QueueDto */
        return $this->commandBus->dispatch(new ResumeQueueCommand($queueId));
    }

    public function closeQueue(string $queueId, ?string $reason = null): QueueDto
    {
        /** @var QueueDto */
        return $this->commandBus->dispatch(new CloseQueueCommand($queueId, $reason));
    }

    public function transfer(TransferQueueDto $dto): QueueEntryDto
    {
        /** @var QueueEntryDto */
        return $this->commandBus->dispatch(new TransferQueueEntryCommand(
            entryId: $dto->entryId,
            destinationQueueId: $dto->destinationQueueId,
            sourceOrderRef: $dto->sourceOrderRef,
            orderType: $dto->orderType,
            reason: $dto->reason,
        ));
    }

    public function cancelForEncounter(string $encounterId, string $reason): int
    {
        /** @var int */
        return $this->commandBus->dispatch(new CancelEncounterQueueEntriesCommand(
            encounterId: $encounterId,
            reason: $reason,
        ));
    }

    public function getActiveForEncounter(string $encounterId): ?QueueEntryDto
    {
        /** @var QueueEntryDto|null */
        return $this->queryBus->dispatch(new GetEncounterActiveQueueEntryQuery($encounterId));
    }

    public function getEntry(string $entryId): ?QueueEntryDto
    {
        /** @var QueueEntryDto|null */
        return $this->queryBus->dispatch(new GetQueueEntryQuery($entryId));
    }

    public function getQueue(string $queueId): ?QueueDto
    {
        /** @var QueueDto|null */
        return $this->queryBus->dispatch(new GetQueueQuery($queueId));
    }

    public function userAssignedToRoom(
        string $tenantId,
        string $facilityId,
        string $queueId,
        string $roomId,
        string $userId,
    ): bool {
        return DB::table('qms_queue_room_staff')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('queue_id', $queueId)
            ->where('room_id', $roomId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function getCallingBoard(string $facilityId, ?string $queueId = null): CallingBoardDto
    {
        /** @var CallingBoardDto */
        return $this->queryBus->dispatch(new GetQueueCallingBoardQuery($facilityId, $queueId));
    }

    public function getCurrentLocation(string $encounterId): ?OperationalLocationDto
    {
        /** @var OperationalLocationDto|null */
        return $this->queryBus->dispatch(new GetCurrentPatientLocationQuery($encounterId));
    }

    /**
     * @return list<PatientMovementDto>
     */
    public function getPatientMovements(string $encounterId): array
    {
        /** @var list<PatientMovementDto> */
        return $this->queryBus->dispatch(new GetPatientMovementsQuery($encounterId));
    }

    public function getQueuePosition(string $entryId): int
    {
        /** @var int */
        return $this->queryBus->dispatch(new GetQueuePositionQuery($entryId));
    }

    public function getQueueWaitMetrics(string $queueId): QueueWaitMetricsDto
    {
        /** @var QueueWaitMetricsDto */
        return $this->queryBus->dispatch(new GetQueueWaitMetricsQuery($queueId));
    }

    /**
     * @return list<QueueDto>
     */
    public function listFacilityQueues(string $facilityId, bool $activeOnly = true): array
    {
        /** @var list<QueueDto> */
        return $this->queryBus->dispatch(new ListFacilityQueuesQuery($facilityId, $activeOnly));
    }

    public function procedureQueueConfigured(string $facilityId, string $definitionId): bool
    {
        $tenantId = $this->context->tenantId();

        return DB::table('qms_procedure_queue_mappings')
            ->join('qms_queues', 'qms_queues.id', '=', 'qms_procedure_queue_mappings.queue_id')
            ->where('qms_procedure_queue_mappings.tenant_id', $tenantId)
            ->where('qms_procedure_queue_mappings.facility_id', $facilityId)
            ->where('qms_procedure_queue_mappings.procedure_definition_id', $definitionId)
            ->where('qms_queues.tenant_id', $tenantId)
            ->where('qms_queues.facility_id', $facilityId)
            ->where('qms_queues.service_capability', 'PROCEDURE')
            ->where('qms_queues.is_active', true)
            ->where('qms_queues.operating_status', '!=', 'CLOSED')
            ->whereExists(function ($query) use ($facilityId, $tenantId): void {
                $query->selectRaw('1')
                    ->from('qms_queue_rooms')
                    ->join('org_rooms', 'org_rooms.id', '=', 'qms_queue_rooms.room_id')
                    ->where('qms_queue_rooms.tenant_id', $tenantId)
                    ->whereColumn('qms_queue_rooms.queue_id', 'qms_queues.id')
                    ->where('org_rooms.tenant_id', $tenantId)
                    ->where('org_rooms.facility_id', $facilityId)
                    ->where('qms_queue_rooms.facility_id', $facilityId)
                    ->where('org_rooms.status', 'ACTIVE');
            })
            ->exists();
    }

    public function procedureQueueId(string $facilityId, string $definitionId): ?string
    {
        $tenantId = $this->context->tenantId();

        $mapping = DB::table('qms_procedure_queue_mappings')
            ->join('qms_queues', 'qms_queues.id', '=', 'qms_procedure_queue_mappings.queue_id')
            ->where('qms_procedure_queue_mappings.tenant_id', $tenantId)
            ->where('qms_procedure_queue_mappings.facility_id', $facilityId)
            ->where('qms_procedure_queue_mappings.procedure_definition_id', $definitionId)
            ->where('qms_queues.tenant_id', $tenantId)
            ->where('qms_queues.facility_id', $facilityId)
            ->where('qms_queues.service_capability', 'PROCEDURE')
            ->where('qms_queues.is_active', true)
            ->where('qms_queues.operating_status', '!=', 'CLOSED')
            ->whereExists(function ($query) use ($facilityId, $tenantId): void {
                $query->selectRaw('1')
                    ->from('qms_queue_rooms')
                    ->join('org_rooms', 'org_rooms.id', '=', 'qms_queue_rooms.room_id')
                    ->where('qms_queue_rooms.tenant_id', $tenantId)
                    ->whereColumn('qms_queue_rooms.queue_id', 'qms_queues.id')
                    ->where('qms_queue_rooms.facility_id', $facilityId)
                    ->where('org_rooms.tenant_id', $tenantId)
                    ->where('org_rooms.facility_id', $facilityId)
                    ->where('org_rooms.status', 'ACTIVE');
            })
            ->value('qms_procedure_queue_mappings.queue_id');

        return is_string($mapping) ? $mapping : null;
    }

    public function serviceCapabilityRequiresOrderProvenance(?string $serviceCapability): bool
    {
        return ServiceCapability::tryFrom((string) $serviceCapability)?->requiresOrderProvenance() ?? false;
    }
}
