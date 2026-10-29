<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts;

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

interface QueueingApi
{
    public function place(PlaceInQueueDto $dto): QueueEntryDto;

    public function callNext(CallNextDto $dto): ?QueueEntryDto;

    public function call(CallTicketDto $dto): QueueEntryDto;

    public function updatePriority(string $entryId, string $priority, string $provenance): ?QueueEntryDto;

    public function recall(RecallTicketDto $dto): QueueEntryDto;

    public function startService(string $entryId): QueueEntryDto;

    public function completeService(string $entryId): QueueEntryDto;

    public function placeHold(string $entryId, string $holdReason, string $holdProvenance): QueueEntryDto;

    public function releaseHold(string $entryId, string $clearanceProvenance): QueueEntryDto;

    public function recordNoResponse(string $entryId): QueueEntryDto;

    public function recordLeftWithoutBeingSeen(string $entryId, ?string $reason = null): QueueEntryDto;

    public function skip(string $entryId, ?string $reason = null): QueueEntryDto;

    public function pauseQueue(string $queueId, string $reason): QueueDto;

    public function resumeQueue(string $queueId): QueueDto;

    public function closeQueue(string $queueId, ?string $reason = null): QueueDto;

    public function transfer(TransferQueueDto $dto): QueueEntryDto;

    public function cancelForEncounter(string $encounterId, string $reason): int;

    public function getActiveForEncounter(string $encounterId): ?QueueEntryDto;

    public function getEntry(string $entryId): ?QueueEntryDto;

    public function getQueue(string $queueId): ?QueueDto;

    public function userAssignedToRoom(
        string $tenantId,
        string $facilityId,
        string $queueId,
        string $roomId,
        string $userId,
    ): bool;

    public function getCallingBoard(string $facilityId, ?string $queueId = null): CallingBoardDto;

    public function getCurrentLocation(string $encounterId): ?OperationalLocationDto;

    /**
     * @return list<PatientMovementDto>
     */
    public function getPatientMovements(string $encounterId): array;

    public function getQueuePosition(string $entryId): int;

    /**
     * Open waiting entries across a facility, oldest first, for monitoring
     * boards. First consumer: the Attention board's "waiting too long" list.
     *
     * @return list<QueueEntryDto>
     */
    public function listWaitingEntries(string $facilityId, int $limit = 200): array;

    /**
     * Open ticket entries grouped for board rendering: waiting, called,
     * in service, on hold. Oldest first within groups, bounded.
     *
     * @return array{waiting: list<QueueEntryDto>, called: list<QueueEntryDto>, inService: list<QueueEntryDto>, onHold: list<QueueEntryDto>}
     */
    public function getOpenTicketEntries(string $facilityId, int $limit = 200): array;

    public function getQueueWaitMetrics(string $queueId): QueueWaitMetricsDto;

    /**
     * @return list<QueueDto>
     */
    public function listFacilityQueues(string $facilityId, bool $activeOnly = true): array;

    public function procedureQueueConfigured(string $facilityId, string $definitionId): bool;

    public function procedureQueueId(string $facilityId, string $definitionId): ?string;
}
