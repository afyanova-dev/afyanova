<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\BillableEventRepository;
use App\Domains\Billing\Domain\Model\BillableEvent;
use App\Domains\Billing\Domain\ValueObject\BillableEventStatus;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see BillableEventRepository} (IC §9; ADR-189 D-B).
 */
final class EloquentBillableEventRepository extends EloquentRepository implements BillableEventRepository
{
    protected function model(): string
    {
        return BillableEvent::class;
    }

    public function getById(string $id): BillableEvent
    {
        return BillableEvent::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(BillableEvent::class, $id);
    }

    public function getByIdForUpdate(string $id): BillableEvent
    {
        return BillableEvent::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(BillableEvent::class, $id);
    }

    public function findBySourceEventIdForUpdate(string $sourceEventId): ?BillableEvent
    {
        return BillableEvent::query()
            ->where('source_event_id', $sourceEventId)
            ->lockForUpdate()
            ->first();
    }

    public function findBySourceRefForUpdate(string $sourceDomain, string $sourceRef): ?BillableEvent
    {
        return BillableEvent::query()
            ->where('source_domain', $sourceDomain)
            ->where('source_ref', $sourceRef)
            ->lockForUpdate()
            ->first();
    }

    public function pendingForItem(string $itemId, string $pendingReason): array
    {
        return array_values(
            BillableEvent::query()
                ->where('item_id', $itemId)
                ->where('status', BillableEventStatus::PENDING)
                ->where('pending_reason', $pendingReason)
                ->orderBy('recorded_at')
                ->get()
                ->all()
        );
    }
}
