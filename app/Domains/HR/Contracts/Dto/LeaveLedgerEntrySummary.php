<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Append-only leave ledger entry summary (25-hr-and-workforce-domain.md §6, §7.9).
 */
final readonly class LeaveLedgerEntrySummary
{
    public function __construct(
        public string $ledgerEntryId,
        public string $tenantId,
        public string $staffId,
        public ?string $staffNumber,
        public ?string $staffName,
        public string $leaveTypeId,
        public ?string $leaveTypeCode,
        public ?string $leaveTypeName,
        public string $kind,
        public float $delta,
        public ?string $sourceRef,
        public string $effectiveDate,
        public ?string $reason,
        public string $postedBy,
        public string $createdAt,
        public int $version,
    ) {}
}
