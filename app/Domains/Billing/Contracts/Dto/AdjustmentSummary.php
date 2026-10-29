<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One financial adjustment (20-billing-and-payments-domain.md §81 / §82;
 * ADR-191 D7-D). Returned by `GetAdjustment` / `GetAccountAdjustments` /
 * `GetPendingAdjustments` via `AdjustmentReader`. NOT `#[TypeScript]`.
 */
final readonly class AdjustmentSummary
{
    public function __construct(
        public string $adjustmentId,
        public string $financialAccountId,
        public string $facilityId,
        public string $patientId,
        public ?string $invoiceId,
        public string $amount,
        public string $currency,
        public string $adjustmentType,
        public string $direction,
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
