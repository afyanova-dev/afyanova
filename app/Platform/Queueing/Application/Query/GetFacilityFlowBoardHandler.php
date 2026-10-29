<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\FacilityFlowBoardDto;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use Carbon\CarbonImmutable;

final class GetFacilityFlowBoardHandler
{
    public function __invoke(GetFacilityFlowBoardQuery $query): FacilityFlowBoardDto
    {
        $now = CarbonImmutable::now();
        $queues = Queue::query()
            ->where('facility_id', $query->facilityId)
            ->get();

        $queueSummaries = [];
        $totalWaiting = 0;
        $totalInService = 0;

        foreach ($queues as $queue) {
            $entries = QueueEntry::query()
                ->where('queue_id', $queue->id)
                ->whereIn('status', [
                    QueueStatus::WAITING->value,
                    QueueStatus::CALLED->value,
                    QueueStatus::IN_SERVICE->value,
                    QueueStatus::NO_RESPONSE->value,
                ])
                ->get();

            $waitingEntries = $entries->where('status', QueueStatus::WAITING);
            $calledEntries = $entries->where('status', QueueStatus::CALLED);
            $inServiceEntries = $entries->where('status', QueueStatus::IN_SERVICE);

            $waitingCount = $waitingEntries->count();
            $calledCount = $calledEntries->count();
            $inServiceCount = $inServiceEntries->count();

            $emergencyCount = $waitingEntries->where('priority.value', 'EMERGENCY')->count();
            $urgentCount = $waitingEntries->where('priority.value', 'URGENT')->count();
            $routineCount = $waitingEntries->where('priority.value', 'ROUTINE')->count();

            $waitTimes = [];
            foreach ($waitingEntries as $entry) {
                $waitTimes[] = max(0, $now->diffInMinutes($entry->created_at, true));
            }

            $avgWaitMinutes = count($waitTimes) > 0 ? round(array_sum($waitTimes) / count($waitTimes), 1) : 0.0;
            $longestWaitMinutes = count($waitTimes) > 0 ? (float) max($waitTimes) : 0.0;

            $totalWaiting += $waitingCount;
            $totalInService += $inServiceCount;

            if ($queue->is_active) {
                $queueSummaries[] = [
                    'queue_id' => $queue->id,
                    'name' => $queue->name,
                    'code' => $queue->code,
                    'operating_status' => $queue->operating_status->value,
                    'waiting_count' => $waitingCount,
                    'called_count' => $calledCount,
                    'in_service_count' => $inServiceCount,
                    'emergency_count' => $emergencyCount,
                    'urgent_count' => $urgentCount,
                    'routine_count' => $routineCount,
                    'avg_wait_minutes' => $avgWaitMinutes,
                    'longest_wait_minutes' => $longestWaitMinutes,
                ];
            }
        }

        return new FacilityFlowBoardDto(
            facilityId: $query->facilityId,
            asOf: $now->toIso8601String(),
            totalWaiting: $totalWaiting,
            totalInService: $totalInService,
            queues: $queueSummaries,
        );
    }
}
