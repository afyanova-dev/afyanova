<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\BillableEvent;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the captured billable-event aggregate (IC §9; ADR-189 D-B).
 * The FIRST repository parameter of `CaptureBillableEvent` / `ReverseBillableEvent`
 * / `RetryBillableEvent`.
 */
interface BillableEventRepository extends Repository
{
    public function getById(string $id): BillableEvent;

    public function getByIdForUpdate(string $id): BillableEvent;

    /** By the source event id (re-delivery idempotency), `lockForUpdate()`. */
    public function findBySourceEventIdForUpdate(string $sourceEventId): ?BillableEvent;

    /** By (source domain, source ref = dispensing id) — the reversal lookup, `lockForUpdate()`. */
    public function findBySourceRefForUpdate(string $sourceDomain, string $sourceRef): ?BillableEvent;

    /**
     * PENDING events for an item with a given `pending_reason` — the
     * `MapItemToBillingService` sweep.
     *
     * @return list<BillableEvent>
     */
    public function pendingForItem(string $itemId, string $pendingReason): array;
}
