<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One captured billable event awaiting a charge — the revenue-leakage worklist
 * (20-billing-and-payments-domain.md §130 / §131; ADR-189 D-G). Returned by
 * `GetPendingBillableEvents` via `ChargeReader`. NOT `#[TypeScript]`.
 */
final readonly class PendingBillableEventSummary
{
    public function __construct(
        public string $billableEventId,
        public string $facilityId,
        public string $sourceDomain,
        public string $sourceEventType,
        public string $sourceRef,
        public string $patientId,
        public string $itemId,
        public string $quantity,
        public string $pendingReason,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
