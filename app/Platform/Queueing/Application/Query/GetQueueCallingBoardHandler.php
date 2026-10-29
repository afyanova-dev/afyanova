<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\CallingBoardDto;
use App\Platform\Queueing\Contracts\DTO\CallingBoardItemDto;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use Carbon\CarbonImmutable;

final readonly class GetQueueCallingBoardHandler
{
    public function __construct(
        private QueueEntryRepository $queueEntryRepository,
        private QueueRepository $queueRepository,
    ) {}

    public function __invoke(GetQueueCallingBoardQuery $query): CallingBoardDto
    {
        $calledEntries = $this->queueEntryRepository->listCalledTickets(
            $query->facilityId,
            $query->queueId,
            10
        );

        $waitingEntries = $this->queueEntryRepository->listWaitingTickets(
            $query->facilityId,
            $query->queueId,
            20
        );

        /** @var array<string, object{code: string, name: string}> $queueCache */
        $queueCache = [];

        $resolveQueue = function (string $queueId) use (&$queueCache): object {
            if (! isset($queueCache[$queueId])) {
                $q = $this->queueRepository->findById($queueId);
                $queueCache[$queueId] = (object) [
                    'code' => $q !== null ? $q->code : 'UNKNOWN',
                    'name' => $q !== null ? $q->name : 'Unknown Queue',
                ];
            }

            return $queueCache[$queueId];
        };

        $mapItem = static fn (QueueEntry $e): CallingBoardItemDto => new CallingBoardItemDto(
            entryId: $e->id,
            ticketNumber: $e->ticket_number,
            queueCode: $resolveQueue($e->queue_id)->code,
            queueName: $resolveQueue($e->queue_id)->name,
            status: $e->status->value,
            calledRoomId: $e->called_room_id,
            calledAt: $e->called_at,
            priority: $e->priority->value,
        );

        $queue = $query->queueId !== null ? $this->queueRepository->findById($query->queueId) : null;

        return new CallingBoardDto(
            facilityId: $query->facilityId,
            queueId: $query->queueId,
            calledTickets: array_map($mapItem, $calledEntries),
            waitingTickets: array_map($mapItem, $waitingEntries),
            asOf: CarbonImmutable::now(),
            operatingStatus: $queue?->operating_status->value ?? 'ACTIVE',
            isPaused: $queue?->operating_status->isPaused() ?? false,
            pauseReason: $queue?->pause_reason,
        );
    }
}
