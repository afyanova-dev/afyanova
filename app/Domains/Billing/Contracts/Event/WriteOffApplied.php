<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * A write-off was APPLIED — an outstanding amount recorded as uncollectable and
 * posted to the ledger as a `WRITE_OFF` / `CREDIT` (20-billing-and-payments-domain.md
 * §78 / §281; ADR-191 D7-A).
 *
 * Written to `shared_outbox_events` in the applying transaction; idempotent
 * consumers key on `eventId`. NOT broadcast. NO Bill.7 consumer (bad-debt
 * reporting is a later concern).
 *
 * PHI-MINIMIZED (ADR-136): identifiers + amount + the coded reason.
 */
final readonly class WriteOffApplied
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $financialAccountId,
        public string $writeOffId,
        public string $amount,
        public string $currency,
        public string $reasonCode,
        public string $appliedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
