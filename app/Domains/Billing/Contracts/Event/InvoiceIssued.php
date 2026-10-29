<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * An Invoice was issued — a request for payment (20-billing-and-payments-domain.md
 * §34 / §37 / §281; ADR-190 D-E).
 *
 * Written to `shared_outbox_events` in the `IssueInvoice` transaction;
 * idempotent consumers key on `eventId`. NOT broadcast. NO Bill.4 consumer —
 * Bill.5 (Payment) allocates against it and derives the PARTIALLY_PAID / PAID
 * figure.
 *
 * PHI-MINIMIZED (ADR-136): identifiers + the `INV-` number + the `net`. NO
 * patient demographics, NO line detail.
 */
final readonly class InvoiceIssued
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $financialAccountId,
        public string $invoiceId,
        public string $invoiceNumber,
        public string $net,
        public string $currency,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
