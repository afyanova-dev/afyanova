<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Repository;

use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Domain\NotFound;

interface QueueEntryRepository
{
    public function save(QueueEntry $entry): void;

    public function findById(string $id): ?QueueEntry;

    /**
     * RLS-scoped load for bus authorization; throws when the entry is not
     * visible in the current tenant scope (never null, never a leak).
     *
     * @throws NotFound
     */
    public function getById(string $id): QueueEntry;

    public function findNextCallable(string $queueId): ?QueueEntry;

    public function findActiveByEncounter(string $encounterId): ?QueueEntry;

    public function findActiveByEncounterAndQueue(string $encounterId, string $queueId): ?QueueEntry;

    public function findBySourceOrderRef(string $sourceOrderRef, string $orderType): ?QueueEntry;

    /**
     * @return list<QueueEntry>
     */
    public function listActiveForEncounter(string $encounterId): array;

    /**
     * @return list<QueueEntry>
     */
    public function listCalledTickets(string $facilityId, ?string $queueId = null, int $limit = 10): array;

    /**
     * @return list<QueueEntry>
     */
    public function listWaitingTickets(string $facilityId, ?string $queueId = null, int $limit = 20): array;
}
