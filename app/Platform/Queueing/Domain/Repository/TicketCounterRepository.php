<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Repository;

interface TicketCounterRepository
{
    /**
     * Atomically increments and returns the next daily sequence number for the given queue.
     */
    public function nextNumber(string $facilityId, string $queueCode, string $counterDate): int;
}
