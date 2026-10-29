<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * An APPLIED write-off was fully reversed — a compensating `WRITE_OFF` /
 * `DEBIT` restored the receivable (20-billing-and-payments-domain.md §72 /
 * §134 / §281; ADR-191 D7-B). The historical write-off row + its reversal
 * metadata are preserved.
 *
 * Written to `shared_outbox_events`; idempotent consumers key on `eventId`.
 * NOT broadcast. NO Bill.7 consumer.
 *
 * PHI-MINIMIZED (ADR-136): identifiers + amount + the coded reason.
 */
final readonly class WriteOffReversed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $financialAccountId,
        public string $writeOffId,
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
