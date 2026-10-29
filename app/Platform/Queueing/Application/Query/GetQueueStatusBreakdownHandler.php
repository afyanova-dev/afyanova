<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Exception\QueueNotFoundException;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;

final class GetQueueStatusBreakdownHandler
{
    /**
     * Count open entries per status for one queue. Every status is present
     * (zero-filled) so the frontend can render a tile per status without
     * guessing. A queue outside the facility reads as not found.
     *
     * @return array<string, int>
     */
    public function __invoke(GetQueueStatusBreakdownQuery $query): array
    {
        $exists = Queue::query()
            ->where('facility_id', $query->facilityId)
            ->where('id', $query->queueId)
            ->exists();

        if (! $exists) {
            throw new QueueNotFoundException("Queue not found: {$query->queueId}");
        }

        $counts = array_fill_keys(
            array_map(static fn (QueueStatus $status): string => $status->value, QueueStatus::cases()),
            0,
        );

        $rows = QueueEntry::query()
            ->where('queue_id', $query->queueId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            if (is_string($row->status) && array_key_exists($row->status, $counts)) {
                $counts[$row->status] = (int) $row->total;
            }
        }

        return $counts;
    }
}
