<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * A posted facility charge was fully reversed — the source dispensing was
 * reversed (20-billing-and-payments-domain.md §113 / §135 / §281; ADR-189 D-H).
 *
 * Written to `shared_outbox_events` in the `ReverseBillableEvent` transaction;
 * idempotent consumers key on `eventId`. NOT broadcast. NO Bill.3 consumer
 * (Bill.4 / Bill.5 — invoice void / refund §102–§104).
 *
 * PHI-MINIMIZED (ADR-136): identifiers + the `amount`. NO patient demographics.
 */
final readonly class ChargeReversed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $chargeId,
        public string $chargeReversalId,
        public string $financialAccountId,
        public string $amount,
        public string $currency,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
