<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One RFQ + its lines + invited suppliers + award outcome (ADR-173 D-F).
 * Returned on the coarse `procurement.access` gate — **no competing quotation
 * prices** (§101; those are `GetRfqQuotations` / `CompareQuotations`, gated on
 * `procurement.quotations.view`). NOT an event payload — `reason` / `terms` /
 * `cancellationReason` ARE returned to an authorised reader.
 *
 * @param  list<RfqLineSummary>  $lines
 * @param  list<RfqInvitationSummary>  $invitations
 * @param  list<RfqAwardSummary>  $awards
 */
final readonly class RfqSummary
{
    /**
     * @param  list<RfqLineSummary>  $lines
     * @param  list<RfqInvitationSummary>  $invitations
     * @param  list<RfqAwardSummary>  $awards
     */
    public function __construct(
        public string $rfqId,
        public string $facilityId,
        public string $rfqNumber,
        public ?string $requisitionId,
        public string $title,
        public ?string $reason,
        public ?string $requiredDate,
        public ?string $closingDate,
        public ?string $terms,
        public string $status,
        public ?string $cancellationReason,
        public ?string $awardedByUserId,
        public ?string $awardedAt,
        public string $createdByUserId,
        public int $quotationCount,
        public array $lines,
        public array $invitations,
        public array $awards,
        public ?string $createdAt,
        public ?bool $requiresEvaluation = null,
    ) {}
}
