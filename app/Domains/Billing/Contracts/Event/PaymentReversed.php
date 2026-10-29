<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * A payment's accounting effect was undone (20-billing-and-payments-domain.md
 * §70 / §281; ADR-191 B5-C). The historical payment + its receipt are
 * preserved; a compensating `PAYMENT` / `DEBIT` ledger entry was posted.
 *
 * Written to `shared_outbox_events` in the `ReversePayment` transaction;
 * idempotent consumers key on `eventId`. NOT broadcast. NO Bill.5 consumer.
 *
 * PHI-MINIMIZED (ADR-136): identifiers + amount + the coded reason.
 */
final readonly class PaymentReversed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $financialAccountId,
        public string $paymentId,
        public string $reversalId,
        public string $amount,
        public string $currency,
        public string $reverseReasonCode,
        public string $reversedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
