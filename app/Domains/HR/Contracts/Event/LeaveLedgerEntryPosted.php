<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when an entry is posted to the append-only leave ledger (25-hr-and-workforce-domain.md §6, §7.9).
 */
final readonly class LeaveLedgerEntryPosted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $ledgerEntryId,
        public string $staffId,
        public string $leaveTypeId,
        public string $kind,
        public float $delta,
        public ?string $sourceRef,
        public string $effectiveDate,
        public string $postedBy,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
