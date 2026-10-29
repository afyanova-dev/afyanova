<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Reader;

use App\Domains\Procurement\Contracts\Dto\PurchaseRequisitionSummary;
use App\Domains\Procurement\Contracts\Dto\RequisitionLineSummary;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\RequisitionLine;
use App\Domains\Procurement\Domain\PurchaseRequisitionReader;
use App\Shared\Domain\NotFound;

final class EloquentPurchaseRequisitionReader implements PurchaseRequisitionReader
{
    public function summary(string $requisitionId): PurchaseRequisitionSummary
    {
        $requisition = PurchaseRequisition::query()->whereKey($requisitionId)->first()
            ?? throw NotFound::aggregate(PurchaseRequisition::class, $requisitionId);

        return $this->toSummary($requisition, $this->hasActivePurchaseOrder((string) $requisition->id));
    }

    public function searchForFacility(string $facilityId, ?string $status = null): array
    {
        $query = PurchaseRequisition::query()->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        $requisitions = $query->orderByDesc('recorded_at')->orderBy('id')->get();
        $requisitionIds = $requisitions->modelKeys();
        $orderedRequisitionIds = $requisitionIds === []
            ? []
            : PurchaseOrder::query()
                ->where('facility_id', $facilityId)
                ->whereIn('requisition_id', $requisitionIds)
                ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
                ->pluck('requisition_id')
                ->map(static fn ($id): string => (string) $id)
                ->flip()
                ->all();

        return array_values($requisitions
            ->map(fn (PurchaseRequisition $r): PurchaseRequisitionSummary => $this->toSummary(
                $r,
                isset($orderedRequisitionIds[(string) $r->id]),
            ))
            ->all());
    }

    public function approvalQueue(string $facilityId): array
    {
        $rows = PurchaseRequisition::query()
            ->where('facility_id', $facilityId)
            ->where('status', PurchaseRequisition::STATUS_SUBMITTED)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (PurchaseRequisition $r): PurchaseRequisitionSummary => $this->toSummary($r))->all());
    }

    private function toSummary(
        PurchaseRequisition $requisition,
        ?bool $hasActivePurchaseOrder = null,
    ): PurchaseRequisitionSummary {
        $lines = RequisitionLine::query()
            ->where('requisition_id', $requisition->id)
            ->orderBy('line_no')
            ->get()
            ->map(fn (RequisitionLine $line): RequisitionLineSummary => new RequisitionLineSummary(
                lineId: (string) $line->id,
                lineNo: (int) $line->line_no,
                lineType: (string) $line->line_type,
                itemRef: $line->item_ref !== null ? (string) $line->item_ref : null,
                itemCode: $line->item_code,
                itemName: $line->item_name,
                description: $line->description,
                quantity: (string) $line->quantity,
                uom: (string) $line->uom,
                unit: (string) $line->unit,
                packSize: $line->pack_size !== null ? (string) $line->pack_size : null,
                requiredDate: $line->required_date?->toDateString(),
                status: (string) $line->status,
            ))
            ->all();

        return new PurchaseRequisitionSummary(
            requisitionId: (string) $requisition->id,
            facilityId: (string) $requisition->facility_id,
            requisitionNumber: (string) $requisition->requisition_number,
            requestingDepartmentRef: $requisition->requesting_department_ref !== null ? (string) $requisition->requesting_department_ref : null,
            priority: (string) $requisition->priority,
            reason: $requisition->reason,
            estimatedValue: $requisition->estimated_value !== null ? (string) $requisition->estimated_value : null,
            emergency: (bool) $requisition->emergency,
            emergencyReason: $requisition->emergency_reason,
            status: (string) $requisition->status,
            reviewedByUserId: $requisition->reviewed_by_user_id !== null ? (string) $requisition->reviewed_by_user_id : null,
            reviewedAt: $requisition->reviewed_at?->toIso8601String(),
            rejectionReason: $requisition->rejection_reason,
            createdByUserId: (string) $requisition->created_by_user_id,
            lines: array_values($lines),
            createdAt: $requisition->created_at?->toIso8601String(),
            hasActivePurchaseOrder: $hasActivePurchaseOrder
                ?? $this->hasActivePurchaseOrder((string) $requisition->id),
            costCentreRef: $requisition->cost_centre_ref,
            budgetRef: $requisition->budget_ref,
        );
    }

    private function hasActivePurchaseOrder(string $requisitionId): bool
    {
        return PurchaseOrder::query()
            ->where('requisition_id', $requisitionId)
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->exists();
    }
}
