<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Event;

/**
 * A Financial Account was opened for a patient at a facility
 * (20-billing-and-payments-domain.md §7, §300; ADR-187 D-F).
 *
 * Written to `shared_outbox_events` in the `OpenFinancialAccount` transaction
 * and relayed asynchronously; idempotent consumers key on `eventId`. NOT
 * broadcast. NO Bill.1 consumer (the account is a container).
 *
 * PHI-MINIMIZED (the ADR-136 deny-list, IC §7.1.1): identifiers + the coded
 * currency + the `FA-` number. NO patient demographics, NO amounts, NO balance.
 */
final readonly class FinancialAccountOpened
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $financialAccountId,
        public string $accountNumber,
        public string $currency,
        public string $openedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
