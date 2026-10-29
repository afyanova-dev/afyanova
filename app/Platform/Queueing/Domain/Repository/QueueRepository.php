<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Repository;

use App\Platform\Queueing\Domain\Model\Queue;

interface QueueRepository
{
    public function save(Queue $queue): void;

    public function findById(string $id): ?Queue;

    /**
     * RLS-scoped load for bus authorization; throws QueueNotFoundException
     * (id only, no names) when the queue is not visible.
     */
    public function getById(string $id): Queue;

    public function findByCode(string $facilityId, string $code): ?Queue;

    /**
     * @return list<Queue>
     */
    public function listByFacility(string $facilityId, bool $onlyActive = true): array;
}
