<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One purchase order + its lines + its amendment history + its receipt ledger +
 * a computed order total (ADR-171 D-J; ADR-172 D-K). NOT an event payload —
 * `notes` / `cancellationReason` / `emergencyReason` ARE returned to an
 * authorised reader.
 *
 * @param  list<PoLineSummary>  $lines
 * @param  list<PoAmendmentSummary>  $amendments
 * @param  list<PoReceiptSummary>  $receipts
 */
final readonly class PurchaseOrderSummary
{
    /**
     * @param  list<PoLineSummary>  $lines
     * @param  list<PoAmendmentSummary>  $amendments
     * @param  list<PoReceiptSummary>  $receipts
     */
    public function __construct(
        public string $purchaseOrderId,
        public string $facilityId,
        public string $poNumber,
        public string $supplierId,
        public ?string $requisitionId,
        public ?string $sourcingRfqId,
        public ?string $sourcingMethod,
        public ?string $contractId,
        public string $currency,
        public string $priority,
        public bool $emergency,
        public ?string $emergencyReason,
        public string $status,
        public ?string $notes,
        public ?string $approvedByUserId,
        public ?string $approvedAt,
        public ?string $issuedByUserId,
        public ?string $issuedAt,
        public ?string $cancellationReason,
        public int $amendmentCount,
        public string $createdByUserId,
        public string $orderTotal,
        public array $lines,
        public array $amendments,
        public array $receipts,
        public ?string $createdAt,
        public ?string $requestingDepartmentRef = null,
        public ?string $sourceRequisitionNumber = null,
        public ?string $closedShortReason = null,
        public ?string $closedShortAt = null,
        public ?string $costCentreRef = null,
        public ?string $budgetRef = null,
    ) {}
}
