<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Repository;

use App\Platform\Queueing\Domain\Exception\QueueNotFoundException;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;

final class EloquentQueueRepository implements QueueRepository
{
    public function save(Queue $queue): void
    {
        $queue->save();
    }

    public function findById(string $id): ?Queue
    {
        return Queue::query()->whereKey($id)->first();
    }

    public function getById(string $id): Queue
    {
        return Queue::query()->whereKey($id)->first()
            ?? throw new QueueNotFoundException("Queue not found: {$id}");
    }

    public function findByCode(string $facilityId, string $code): ?Queue
    {
        return Queue::query()
            ->where('facility_id', $facilityId)
            ->where('code', $code)
            ->first();
    }

    /**
     * @return list<Queue>
     */
    public function listByFacility(string $facilityId, bool $onlyActive = true): array
    {
        $query = Queue::query()->where('facility_id', $facilityId);

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        /** @var list<Queue> */
        return $query->orderBy('name')->get()->all();
    }
}
