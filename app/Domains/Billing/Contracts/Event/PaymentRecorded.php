<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * Money was received against a Financial Account
 * (20-billing-and-payments-domain.md §57 / §281; ADR-191 B5-C).
 *
 * Written to `shared_outbox_events` in the `RecordPayment` transaction;
 * idempotent consumers key on `eventId`. NOT broadcast. NO Bill.5 consumer —
 * the invoice paid-status is reader-derived from allocations (Bill.5c).
 *
 * PHI-MINIMIZED (ADR-136): identifiers + method + amount. NO payer
 * demographics, NO reference string.
 */
final readonly class PaymentRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $financialAccountId,
        public string $paymentId,
        public string $method,
        public string $amount,
        public string $currency,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
