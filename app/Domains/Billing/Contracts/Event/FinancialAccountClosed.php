<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * A Financial Account was closed (20-billing-and-payments-domain.md §301;
 * ADR-187 D-F). Its transaction history stays queryable.
 *
 * Written to `shared_outbox_events` in the `CloseFinancialAccount` transaction;
 * idempotent consumers key on `eventId`. NOT broadcast. NO Bill.1 consumer.
 *
 * PHI-MINIMIZED (the ADR-136 deny-list): identifiers + the coded close reason +
 * the `FA-` number. NO patient demographics, NO amounts.
 */
final readonly class FinancialAccountClosed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $financialAccountId,
        public string $accountNumber,
        public string $closeReasonCode,
        public string $closedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
