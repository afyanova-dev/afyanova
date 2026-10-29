<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * A facility charge was posted for a consumed billable item/service
 * (20-billing-and-payments-domain.md §14 / §281; ADR-189 D-H).
 *
 * Written to `shared_outbox_events` in the `CaptureBillableEvent` /
 * `RetryBillableEvent` transaction; idempotent consumers key on `eventId`. NOT
 * broadcast. NO Bill.3 consumer — Bill.4 (Invoice) groups POSTED charges.
 *
 * PHI-MINIMIZED (ADR-136): identifiers + the coded `billingServiceId` + the
 * `amount`. NO patient demographics, NO medication name.
 */
final readonly class ChargeCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $financialAccountId,
        public string $chargeId,
        public string $billingServiceId,
        public string $billableEventId,
        public string $amount,
        public string $currency,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
