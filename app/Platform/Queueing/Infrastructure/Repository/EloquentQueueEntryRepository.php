<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Repository;

use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentQueueEntryRepository extends EloquentRepository implements QueueEntryRepository
{
    protected function model(): string
    {
        return QueueEntry::class;
    }

    public function save(AggregateRoot $aggregate): void
    {
        parent::save($aggregate);
    }

    public function findById(string $id): ?QueueEntry
    {
        return QueueEntry::query()->whereKey($id)->first();
    }

    public function getById(string $id): QueueEntry
    {
        return QueueEntry::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(QueueEntry::class, $id);
    }

    public function findNextCallable(string $queueId): ?QueueEntry
    {
        /** @var QueueEntry|null */
        return QueueEntry::query()
            ->where('queue_id', $queueId)
            ->whereIn('status', [QueueStatus::WAITING->value, QueueStatus::NO_RESPONSE->value])
            ->orderByRaw("CASE priority WHEN 'EMERGENCY' THEN 40 WHEN 'URGENT' THEN 30 WHEN 'PRIORITY' THEN 20 ELSE 10 END DESC")
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();
    }

    public function findActiveByEncounter(string $encounterId): ?QueueEntry
    {
        /** @var QueueEntry|null */
        return QueueEntry::query()
            ->where('encounter_id', $encounterId)
            ->whereNotIn('status', [
                QueueStatus::COMPLETED->value,
                QueueStatus::EXPIRED->value,
                QueueStatus::LEFT_WITHOUT_BEING_SEEN->value,
            ])
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function findActiveByEncounterAndQueue(string $encounterId, string $queueId): ?QueueEntry
    {
        /** @var QueueEntry|null */
        return QueueEntry::query()
            ->where('encounter_id', $encounterId)
            ->where('queue_id', $queueId)
            ->whereNotIn('status', [
                QueueStatus::COMPLETED->value,
                QueueStatus::EXPIRED->value,
                QueueStatus::LEFT_WITHOUT_BEING_SEEN->value,
            ])
            ->first();
    }

    public function findBySourceOrderRef(string $sourceOrderRef, string $orderType): ?QueueEntry
    {
        /** @var QueueEntry|null */
        return QueueEntry::query()
            ->where('source_order_ref', $sourceOrderRef)
            ->where('order_type', $orderType)
            ->first();
    }

    /**
     * @return list<QueueEntry>
     */
    public function listActiveForEncounter(string $encounterId): array
    {
        /** @var list<QueueEntry> */
        return QueueEntry::query()
            ->where('encounter_id', $encounterId)
            ->whereNotIn('status', [
                QueueStatus::COMPLETED->value,
                QueueStatus::EXPIRED->value,
                QueueStatus::LEFT_WITHOUT_BEING_SEEN->value,
            ])
            ->get()
            ->all();
    }

    /**
     * @return list<QueueEntry>
     */
    public function listCalledTickets(string $facilityId, ?string $queueId = null, int $limit = 10): array
    {
        $query = QueueEntry::query()
            ->where('facility_id', $facilityId)
            ->where('status', QueueStatus::CALLED->value);

        if ($queueId !== null) {
            $query->where('queue_id', $queueId);
        }

        /** @var list<QueueEntry> */
        return $query->orderBy('called_at', 'desc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return list<QueueEntry>
     */
    public function listWaitingTickets(string $facilityId, ?string $queueId = null, int $limit = 20): array
    {
        $query = QueueEntry::query()
            ->where('facility_id', $facilityId)
            ->where('status', QueueStatus::WAITING->value);

        if ($queueId !== null) {
            $query->where('queue_id', $queueId);
        }

        /** @var list<QueueEntry> */
        return $query
            ->orderByRaw("CASE priority WHEN 'EMERGENCY' THEN 40 WHEN 'URGENT' THEN 30 WHEN 'PRIORITY' THEN 20 ELSE 10 END DESC")
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get()
            ->all();
    }
}
