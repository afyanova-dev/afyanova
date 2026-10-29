<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Summary of a third-party payer reimbursement claim (21-insurance-and-payer-domain.md §76;
 * ADR-204).
 * NOT #[TypeScript].
 */
final readonly class ClaimSummary
{
    /**
     * @param  list<ClaimLineSummary>  $lines
     * @param  array<string, mixed>|null  $readinessNotes
     */
    public function __construct(
        public string $claimId,
        public string $tenantId,
        public string $facilityId,
        public string $claimNumber,
        public string $invoiceId,
        public string $patientId,
        public string $payerId,
        public string $planId,
        public string $memberCoverageId,
        public ?string $contractId,
        public ?string $authorizationId,
        public string $status,
        public string $totalClaimedAmount,
        public string $currency,
        public ?string $readinessStatus,
        public ?string $readinessCheckedAt,
        public ?array $readinessNotes,
        public ?string $primaryDiagnosisCode,
        public ?string $externalReference,
        public ?string $notes,
        public ?string $supersedesClaimId,
        public ?string $supersededByClaimId,
        public ?string $supersededAt,
        public ?string $submittedAt,
        public ?string $submittedByUserId,
        public ?string $cancelledAt,
        public ?string $cancelledByUserId,
        public ?string $cancellationReason,
        public string $createdByUserId,
        public array $lines,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
