<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One write-off (20-billing-and-payments-domain.md §78 / §82; ADR-191 D7-A).
 * Returned by `GetWriteOff` / `GetAccountWriteOffs` / `GetPendingWriteOffs` via
 * `WriteOffReader`. NOT `#[TypeScript]`. Money fields are scale-2 numeric strings.
 */
final readonly class WriteOffSummary
{
    public function __construct(
        public string $writeOffId,
        public string $financialAccountId,
        public string $facilityId,
        public string $patientId,
        public ?string $invoiceId,
        public string $amount,
        public string $currency,
        public string $reasonCode,
        public string $status,
        public ?string $note,
        public bool $dualControl,
        public string $requestedAt,
        public string $requestedByUserId,
        public ?string $approvedAt,
        public ?string $approvedByUserId,
        public ?string $rejectedAt,
        public ?string $rejectedByUserId,
        public ?string $rejectReasonCode,
        public ?string $reversalId,
        public ?string $reversedAt,
        public ?string $reverseReasonCode,
        public string $recordedAt,
    ) {}
}
