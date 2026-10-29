<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Summary of a claim adjudication record (21-insurance-and-payer-domain.md §84–§87; ADR-205).
 * NOT #[TypeScript].
 */
final readonly class AdjudicationSummary
{
    /**
     * @param  list<AdjudicationLineSummary>  $lines
     */
    public function __construct(
        public string $adjudicationId,
        public string $tenantId,
        public string $facilityId,
        public string $adjudicationNumber,
        public string $claimId,
        public string $payerId,
        public string $status,
        public string $totalClaimedAmount,
        public string $totalApprovedAmount,
        public string $totalDeniedAmount,
        public string $totalPatientResponsibility,
        public string $totalContractualAdjustment,
        public string $currency,
        public string $adjudicationDate,
        public ?string $externalReference,
        public ?string $notes,
        public ?string $adjudicatedByUserId,
        public string $createdByUserId,
        public array $lines,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
