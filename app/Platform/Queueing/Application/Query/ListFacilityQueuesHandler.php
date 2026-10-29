<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\QueueDto;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use Illuminate\Support\Facades\DB;

final class ListFacilityQueuesHandler
{
    public function __construct(
        private readonly QueueRepository $queueRepository,
    ) {}

    /**
     * @return list<QueueDto>
     */
    public function __invoke(ListFacilityQueuesQuery $query): array
    {
        $queues = $this->queueRepository->listByFacility($query->facilityId, $query->onlyActive);
        $roomNames = DB::table('qms_queue_rooms')
            ->join('org_rooms', 'org_rooms.id', '=', 'qms_queue_rooms.room_id')
            ->where('qms_queue_rooms.facility_id', $query->facilityId)
            ->where('org_rooms.status', 'ACTIVE')
            ->orderBy('org_rooms.name')
            ->get(['qms_queue_rooms.queue_id', 'org_rooms.name'])
            ->groupBy('queue_id');

        return array_map(
            static fn (Queue $queue): QueueDto => new QueueDto(
                id: $queue->id,
                tenantId: $queue->tenant_id,
                facilityId: $queue->facility_id,
                code: $queue->code,
                name: $queue->name,
                departmentId: $queue->department_id,
                serviceCapability: $queue->service_capability,
                ticketPrefix: $queue->ticket_prefix,
                isActive: $queue->is_active,
                createdAt: $queue->created_at,
                roomNames: $roomNames->get($queue->id, collect())
                    ->pluck('name')
                    ->map(static fn ($name): string => (string) $name)
                    ->values()
                    ->all(),
            ),
            $queues
        );
    }
}
