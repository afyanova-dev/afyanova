<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * One purchase requisition + its lines (ADR-171 D-J). NOT an event payload —
 * `reason` / `rejectionReason` / `emergencyReason` ARE returned to an
 * authorised reader (the `#[ProtectedQuery]` gate + facility refinement IS the
 * authorization — cf. Rad.1 `GetImagingOrder`).
 *
 * @param  list<RequisitionLineSummary>  $lines
 */
final readonly class PurchaseRequisitionSummary
{
    /**
     * @param  list<RequisitionLineSummary>  $lines
     */
    public function __construct(
        public string $requisitionId,
        public string $facilityId,
        public string $requisitionNumber,
        public ?string $requestingDepartmentRef,
        public string $priority,
        public ?string $reason,
        public ?string $estimatedValue,
        public bool $emergency,
        public ?string $emergencyReason,
        public string $status,
        public ?string $reviewedByUserId,
        public ?string $reviewedAt,
        public ?string $rejectionReason,
        public string $createdByUserId,
        public array $lines,
        public ?string $createdAt,
        public bool $hasActivePurchaseOrder = false,
        public ?string $costCentreRef = null,
        public ?string $budgetRef = null,
    ) {}
}
