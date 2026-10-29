<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Summary of a remittance line item (21-insurance-and-payer-domain.md §98–§104; ADR-205).
 * NOT #[TypeScript].
 */
final readonly class RemittanceLineSummary
{
    public function __construct(
        public string $lineId,
        public string $remittanceId,
        public int $lineNumber,
        public ?string $claimId,
        public ?string $adjudicationId,
        public ?string $externalClaimReference,
        public string $claimedAmount,
        public string $paidAmount,
        public string $contractualAdjustment,
        public string $patientResponsibility,
        public string $deniedAmount,
        public string $status,
        public ?string $discrepancyReason,
    ) {}
}
