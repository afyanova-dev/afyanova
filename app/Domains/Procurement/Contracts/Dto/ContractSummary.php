<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One purchase contract + its lines + its amendment history (ADR-176 D-F;
 * ADR-178 D-F). Returned on the coarse `procurement.access` gate — a contract
 * price is an agreed price with ONE supplier (the `PoLineSummary.unitPrice`
 * tier), NOT a competing-quotation price. `isExpired` is computed
 * (`expiry_date < today`); `poCount` is the number of POs citing this contract.
 * `supersedesContractId` is set on a renewal row; `supersededByContractId` is
 * the reverse lookup (the renewal that replaced this contract).
 *
 * @param  list<ContractLineSummary>  $lines
 * @param  list<ContractAmendmentSummary>  $amendments
 */
final readonly class ContractSummary
{
    /**
     * @param  list<ContractLineSummary>  $lines
     * @param  list<ContractAmendmentSummary>  $amendments
     */
    public function __construct(
        public string $contractId,
        public string $contractNumber,
        public string $supplierId,
        public string $title,
        public ?string $reason,
        public string $effectiveDate,
        public string $expiryDate,
        public ?string $terms,
        public string $currency,
        public string $status,
        public bool $isExpired,
        public ?string $terminationReason,
        public ?string $supersedesContractId,
        public ?string $supersededByContractId,
        public int $amendmentCount,
        public ?string $activatedByUserId,
        public ?string $activatedAt,
        public string $createdByUserId,
        public int $poCount,
        public array $lines,
        public array $amendments,
        public ?string $createdAt,
    ) {}
}
