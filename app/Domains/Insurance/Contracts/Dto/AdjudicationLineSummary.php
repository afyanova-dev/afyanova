<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Summary of an adjudicated claim line item (21-insurance-and-payer-domain.md §84; ADR-205).
 * NOT #[TypeScript].
 */
final readonly class AdjudicationLineSummary
{
    public function __construct(
        public string $lineId,
        public string $adjudicationId,
        public string $claimLineId,
        public int $lineNumber,
        public string $status,
        public string $claimedAmount,
        public string $approvedAmount,
        public string $deniedAmount,
        public string $patientResponsibility,
        public string $contractualAdjustment,
        public ?string $denialReasonCode,
        public ?string $remarks,
    ) {}
}
