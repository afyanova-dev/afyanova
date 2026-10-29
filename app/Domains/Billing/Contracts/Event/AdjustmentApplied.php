<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * A post-charge account-level adjustment was APPLIED — a signed `ADJUSTMENT`
 * ledger entry posted (20-billing-and-payments-domain.md §81 / §281; ADR-191
 * D7-D). Written to `shared_outbox_events`; idempotent consumers key on
 * `eventId`. NOT broadcast. NO Bill.7 consumer.
 *
 * PHI-MINIMIZED (ADR-136): identifiers + amount + type + direction.
 */
final readonly class AdjustmentApplied
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $financialAccountId,
        public string $adjustmentId,
        public string $amount,
        public string $currency,
        public string $adjustmentType,
        public string $direction,
        public string $appliedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
