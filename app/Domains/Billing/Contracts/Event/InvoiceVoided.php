<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * An Invoice was voided (20-billing-and-payments-domain.md §136 / §281;
 * ADR-190 D-E). The historical invoice + its lines are preserved; the lines'
 * charges are released for future invoicing.
 *
 * Written to `shared_outbox_events` in the `VoidInvoice` transaction; idempotent
 * consumers key on `eventId`. NOT broadcast. NO Bill.4 consumer.
 *
 * PHI-MINIMIZED (ADR-136): identifiers + the coded void reason.
 */
final readonly class InvoiceVoided
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $invoiceId,
        public string $voidReasonCode,
        public string $voidedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
