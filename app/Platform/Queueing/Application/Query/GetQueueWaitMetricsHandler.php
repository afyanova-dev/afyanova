<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\QueueWaitMetricsDto;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Exception\QueueNotFoundException;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use Carbon\CarbonImmutable;

final readonly class GetQueueWaitMetricsHandler
{
    public function __construct(
        private QueueRepository $queueRepository,
    ) {}

    public function __invoke(GetQueueWaitMetricsQuery $query): QueueWaitMetricsDto
    {
        $queue = $this->queueRepository->findById($query->queueId)
            ?? throw new QueueNotFoundException("Queue not found: {$query->queueId}");

        $now = CarbonImmutable::now();
        $startOfDay = $now->startOfDay();

        $activeCount = QueueEntry::query()
            ->where('queue_id', $query->queueId)
            ->whereIn('status', [
                QueueStatus::WAITING->value,
                QueueStatus::CALLED->value,
                QueueStatus::IN_SERVICE->value,
                QueueStatus::HOLD->value,
                QueueStatus::SKIPPED->value,
            ])
            ->count();

        $completedToday = QueueEntry::query()
            ->where('queue_id', $query->queueId)
            ->where('status', QueueStatus::COMPLETED->value)
            ->where('completed_at', '>=', $startOfDay)
            ->get();

        $lwbsCount = QueueEntry::query()
            ->where('queue_id', $query->queueId)
            ->where('status', QueueStatus::LEFT_WITHOUT_BEING_SEEN->value)
            ->where('completed_at', '>=', $startOfDay)
            ->count();

        $totalWaitSeconds = 0;
        $waitEntriesCount = 0;
        $totalServiceSeconds = 0;
        $serviceEntriesCount = 0;

        foreach ($completedToday as $entry) {
            if ($entry->called_at !== null) {
                $totalWaitSeconds += max(0, $entry->called_at->diffInSeconds($entry->created_at, true));
                $waitEntriesCount++;
            }
            if ($entry->service_started_at !== null && $entry->completed_at !== null) {
                $totalServiceSeconds += max(0, $entry->completed_at->diffInSeconds($entry->service_started_at, true));
                $serviceEntriesCount++;
            }
        }

        $avgWait = $waitEntriesCount > 0 ? (int) round($totalWaitSeconds / $waitEntriesCount) : 0;
        $avgService = $serviceEntriesCount > 0 ? (int) round($totalServiceSeconds / $serviceEntriesCount) : 0;

        return new QueueWaitMetricsDto(
            queueId: $queue->id,
            queueCode: $queue->code,
            averageWaitSeconds: $avgWait,
            averageServiceSeconds: $avgService,
            activeTicketsCount: $activeCount,
            completedTodayCount: count($completedToday),
            lwbsCount: $lwbsCount,
            asOf: $now,
        );
    }
}
