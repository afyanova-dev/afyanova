<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Reader;

use App\Domains\Procurement\Contracts\Dto\ContractPriceReference;
use App\Domains\Procurement\Contracts\Dto\ItemPricePoint;
use App\Domains\Procurement\Contracts\Dto\ItemPriceReference;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLinePage;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLineRef;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderRef;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderSearchPage;
use App\Domains\Procurement\Contracts\Dto\PoAmendmentSummary;
use App\Domains\Procurement\Contracts\Dto\PoLineSummary;
use App\Domains\Procurement\Contracts\Dto\PoReceiptSummary;
use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;
use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Model\PoAmendment;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PoReceipt;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\PurchaseOrderReader;
use App\Domains\Procurement\Domain\Quantity;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EloquentPurchaseOrderReader implements PurchaseOrderReader
{
    public function __construct(private readonly ContractRepository $contracts) {}

    public function summary(string $purchaseOrderId): PurchaseOrderSummary
    {
        $po = PurchaseOrder::query()->whereKey($purchaseOrderId)->first()
            ?? throw NotFound::aggregate(PurchaseOrder::class, $purchaseOrderId);

        return $this->toSummary($po);
    }

    public function findSummaryByNumber(string $facilityId, string $poNumber): ?PurchaseOrderSummary
    {
        $po = PurchaseOrder::query()
            ->where('facility_id', $facilityId)
            ->where('po_number', $poNumber)
            ->first();

        return $po === null ? null : $this->toSummary($po);
    }

    public function searchForFacility(string $facilityId, ?string $status = null): array
    {
        $query = PurchaseOrder::query()->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (PurchaseOrder $po): PurchaseOrderSummary => $this->toSummary($po))
                ->all()
        );
    }

    public function searchForFacilityAndDepartments(
        string $facilityId,
        array $departmentRefs,
        ?string $status = null,
    ): array {
        if ($departmentRefs === []) {
            return [];
        }

        $requisitions = PurchaseRequisition::query()
            ->select('id')
            ->where('facility_id', $facilityId)
            ->whereIn('requesting_department_ref', $departmentRefs);
        $query = PurchaseOrder::query()
            ->where('facility_id', $facilityId)
            ->whereIn('requisition_id', $requisitions);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (PurchaseOrder $po): PurchaseOrderSummary => $this->toSummary($po))
                ->all()
        );
    }

    public function openForFacility(string $facilityId): array
    {
        $rows = PurchaseOrder::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_ISSUED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED, // ADR-172 D-K — Proc.1b widens "open"
            ])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (PurchaseOrder $po): PurchaseOrderSummary => $this->toSummary($po))->all());
    }

    public function openForFacilityAndDepartments(string $facilityId, array $departmentRefs): array
    {
        if ($departmentRefs === []) {
            return [];
        }

        $requisitions = PurchaseRequisition::query()
            ->select('id')
            ->where('facility_id', $facilityId)
            ->whereIn('requesting_department_ref', $departmentRefs);

        $rows = PurchaseOrder::query()
            ->where('facility_id', $facilityId)
            ->whereIn('requisition_id', $requisitions)
            ->whereIn('status', [
                PurchaseOrder::STATUS_ISSUED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (PurchaseOrder $po): PurchaseOrderSummary => $this->toSummary($po))->all());
    }

    /**
     * P0-03 — search open POs without loading their lines. Each result carries
     * the open/remaining line counts, so a receiving picker can show what is
     * still deliverable even when the order has hundreds of lines.
     *
     * @param  list<string>  $departmentRefs  empty = facility-wide
     */
    public function searchReceivable(
        string $facilityId,
        array $departmentRefs,
        string $query,
        int $page,
        int $perPage,
    ): OpenPurchaseOrderSearchPage {
        $orders = $this->openOrdersQuery($facilityId, $departmentRefs);

        $this->applyOrderSearch($orders, $query);

        $paginator = $orders
            ->orderByDesc('issued_at')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $refs = [];

        foreach ($paginator->items() as $po) {
            /** @var PurchaseOrder $po */
            $lines = PoLine::query()
                ->where('po_id', $po->id)
                ->where('line_type', PoLine::LINE_TYPE_STOCK)
                ->where('status', PoLine::STATUS_OPEN);

            $openLineCount = (clone $lines)->count();
            $remainingLineCount = (clone $lines)
                ->whereRaw('ordered_base_qty > received_qty')
                ->count();

            $refs[] = new OpenPurchaseOrderRef(
                purchaseOrderId: (string) $po->id,
                poNumber: (string) $po->po_number,
                facilityId: (string) $po->facility_id,
                status: (string) $po->status,
                lines: [],
                openLineCount: $openLineCount,
                remainingLineCount: $remainingLineCount,
            );
        }

        return new OpenPurchaseOrderSearchPage(
            orders: $refs,
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
        );
    }

    /**
     * P0-03 — one page of open STOCK lines for a selected PO, filtered by line
     * number, item code or item name.
     *
     * @param  list<string>  $departmentRefs  empty = facility-wide
     */
    public function receivableLines(
        string $facilityId,
        array $departmentRefs,
        string $purchaseOrderId,
        string $query,
        int $page,
        int $perPage,
    ): OpenPurchaseOrderLinePage {
        $po = $this->openOrdersQuery($facilityId, $departmentRefs)
            ->whereKey($purchaseOrderId)
            ->first();

        if ($po === null) {
            throw NotFound::aggregate(PurchaseOrder::class, $purchaseOrderId);
        }

        $lines = PoLine::query()
            ->where('po_id', $po->id)
            ->where('line_type', PoLine::LINE_TYPE_STOCK)
            ->where('status', PoLine::STATUS_OPEN)
            ->whereRaw('ordered_base_qty > received_qty');

        $this->applyLineSearch($lines, $query);

        $paginator = $lines
            ->orderBy('line_no')
            ->paginate($perPage, ['*'], 'page', $page);

        $refs = [];

        foreach ($paginator->items() as $line) {
            /** @var PoLine $line */
            $remainingQty = Quantity::subtract(
                (string) $line->ordered_base_qty,
                (string) $line->received_qty,
            );

            if (! Quantity::isPositive($remainingQty)) {
                continue;
            }

            $refs[] = new OpenPurchaseOrderLineRef(
                lineId: (string) $line->id,
                lineNo: (int) $line->line_no,
                itemRef: (string) $line->item_ref,
                itemCode: $line->item_code,
                itemName: $line->item_name,
                orderedQty: (string) $line->ordered_base_qty,
                receivedQty: (string) $line->received_qty,
                remainingQty: $remainingQty,
                uom: (string) $line->uom,
                enteredUnit: $line->pack_size !== null ? (string) $line->unit : null,
                enteredQty: $line->pack_size !== null ? (string) $line->ordered_qty : null,
                packSize: $line->pack_size !== null ? (string) $line->pack_size : null,
            );
        }

        return new OpenPurchaseOrderLinePage(
            lines: $refs,
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
        );
    }

    /**
     * @param  list<string>  $departmentRefs
     * @return Builder<PurchaseOrder>
     */
    private function openOrdersQuery(string $facilityId, array $departmentRefs): Builder
    {
        $query = PurchaseOrder::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_ISSUED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('pcr_po_lines')
                    ->whereColumn('pcr_po_lines.po_id', 'pcr_purchase_orders.id')
                    ->where('pcr_po_lines.line_type', PoLine::LINE_TYPE_STOCK)
                    ->where('pcr_po_lines.status', PoLine::STATUS_OPEN)
                    ->whereRaw('pcr_po_lines.ordered_base_qty > pcr_po_lines.received_qty');
            });

        if ($departmentRefs !== []) {
            $query->whereIn('requisition_id', PurchaseRequisition::query()
                ->select('id')
                ->where('facility_id', $facilityId)
                ->whereIn('requesting_department_ref', $departmentRefs));
        }

        return $query;
    }

    /** @param Builder<PurchaseOrder> $query */
    private function applyOrderSearch(Builder $query, string $search): void
    {
        $term = trim($search);

        if ($term === '') {
            return;
        }

        $like = $this->likeTerm($term);
        $lineNumber = ctype_digit($term) ? (int) $term : null;

        $query->where(function (Builder $q) use ($like, $lineNumber): void {
            $q->where('po_number', 'ilike', $like)
                ->orWhereIn('supplier_id', Supplier::query()
                    ->select('id')
                    ->where('name', 'ilike', $like))
                ->orWhereIn('id', PoLine::query()
                    ->select('po_id')
                    ->where('line_type', PoLine::LINE_TYPE_STOCK)
                    ->where('status', PoLine::STATUS_OPEN)
                    ->whereRaw('ordered_base_qty > received_qty')
                    ->where(function (Builder $lineQuery) use ($like, $lineNumber): void {
                        $lineQuery->where('item_code', 'ilike', $like)
                            ->orWhere('item_name', 'ilike', $like);

                        if ($lineNumber !== null) {
                            $lineQuery->orWhere('line_no', $lineNumber);
                        }
                    }));
        });
    }

    /** @param Builder<PoLine> $query */
    private function applyLineSearch(Builder $query, string $search): void
    {
        $term = trim($search);

        if ($term === '') {
            return;
        }

        $like = $this->likeTerm($term);
        $lineNumber = ctype_digit($term) ? (int) $term : null;

        $query->where(function (Builder $q) use ($like, $lineNumber): void {
            $q->where('item_code', 'ilike', $like)
                ->orWhere('item_name', 'ilike', $like);

            if ($lineNumber !== null) {
                $q->orWhere('line_no', $lineNumber);
            }
        });
    }

    private function likeTerm(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    public function itemPriceReference(
        string $facilityId,
        array $departmentRefs,
        string $supplierId,
        string $itemId,
    ): ItemPriceReference {
        $lastSupplier = $this->lastPurchasePrice(
            $facilityId,
            $departmentRefs,
            $itemId,
            $supplierId,
        );
        $lastAny = $this->lastPurchasePrice($facilityId, $departmentRefs, $itemId, null);

        return new ItemPriceReference(
            itemId: $itemId,
            supplierId: $supplierId,
            currency: 'TZS',
            lastSupplierPrice: $lastSupplier,
            lastAnySupplierPrice: $lastAny,
            contractPrice: $this->contractPrice($supplierId, $itemId),
        );
    }

    /**
     * @param  list<string>  $departmentRefs
     */
    private function lastPurchasePrice(
        string $facilityId,
        array $departmentRefs,
        string $itemId,
        ?string $supplierId,
    ): ?ItemPricePoint {
        $query = PoLine::query()
            ->join('pcr_purchase_orders as po', 'po.id', '=', 'pcr_po_lines.po_id')
            ->where('po.facility_id', $facilityId)
            ->where('pcr_po_lines.item_ref', $itemId)
            ->where('pcr_po_lines.line_type', PoLine::LINE_TYPE_STOCK)
            ->where('pcr_po_lines.status', '!=', PoLine::STATUS_CANCELLED)
            ->where('po.status', '!=', PurchaseOrder::STATUS_CANCELLED);

        if ($supplierId !== null) {
            $query->where('po.supplier_id', $supplierId);
        }

        if ($departmentRefs !== []) {
            $query->whereIn('po.requisition_id', PurchaseRequisition::query()
                ->select('id')
                ->where('facility_id', $facilityId)
                ->whereIn('requesting_department_ref', $departmentRefs));
        }

        $row = $query
            ->orderByDesc('po.recorded_at')
            ->orderByDesc('po.id')
            ->orderByDesc('pcr_po_lines.line_no')
            ->select([
                'pcr_po_lines.unit_price as price_unit_price',
                'pcr_po_lines.unit as price_unit',
                'pcr_po_lines.uom as price_uom',
                'pcr_po_lines.pack_size as price_pack_size',
                'po.supplier_id as price_supplier_id',
                'po.po_number as price_po_number',
                'po.recorded_at as price_recorded_at',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        $packSize = $row->price_pack_size !== null ? (string) $row->price_pack_size : null;
        $unitPrice = Money::of((string) $row->price_unit_price);

        return new ItemPricePoint(
            supplierId: (string) $row->price_supplier_id,
            poNumber: (string) $row->price_po_number,
            unitPrice: $unitPrice,
            baseUnitPrice: $this->baseUnitPrice($unitPrice, $packSize),
            unit: (string) $row->price_unit,
            uom: (string) $row->price_uom,
            packSize: $packSize,
            orderedAt: CarbonImmutable::parse((string) $row->price_recorded_at)->toIso8601String(),
        );
    }

    private function contractPrice(string $supplierId, string $itemId): ?ContractPriceReference
    {
        $today = CarbonImmutable::now()->toDateString();

        $rows = ContractLine::query()
            ->join('pcr_contracts as c', 'c.id', '=', 'pcr_contract_lines.contract_id')
            ->where('c.supplier_id', $supplierId)
            ->where('c.status', Contract::STATUS_ACTIVE)
            ->whereDate('c.effective_date', '<=', $today)
            ->whereDate('c.expiry_date', '>=', $today)
            ->where('pcr_contract_lines.item_ref', $itemId)
            ->where('pcr_contract_lines.line_type', ContractLine::LINE_TYPE_STOCK)
            ->orderByDesc('c.effective_date')
            ->orderByDesc('c.recorded_at')
            ->orderBy('pcr_contract_lines.line_no')
            ->select([
                'pcr_contract_lines.id as price_contract_line_id',
                'pcr_contract_lines.agreed_unit_price as price_unit_price',
                'pcr_contract_lines.quantity_ceiling as price_quantity_ceiling',
                'pcr_contract_lines.uom as price_uom',
                'c.id as price_contract_id',
                'c.contract_number as price_contract_number',
                'c.effective_date as price_effective_date',
                'c.expiry_date as price_expiry_date',
            ])
            ->get();

        foreach ($rows as $row) {
            $remaining = null;
            $ceiling = $row->price_quantity_ceiling !== null
                ? Quantity::of((string) $row->price_quantity_ceiling)
                : null;

            if ($ceiling !== null) {
                $committed = $this->contracts->committedQuantityForContractLine(
                    (string) $row->price_contract_line_id,
                );

                if (bccomp($ceiling, $committed, Quantity::SCALE) <= 0) {
                    continue;
                }

                $remaining = Quantity::subtract($ceiling, $committed);
            }

            return new ContractPriceReference(
                contractId: (string) $row->price_contract_id,
                contractNumber: (string) $row->price_contract_number,
                unitPrice: Money::of((string) $row->price_unit_price),
                uom: (string) $row->price_uom,
                remaining: $remaining,
                effectiveDate: CarbonImmutable::parse((string) $row->price_effective_date)->toDateString(),
                expiryDate: CarbonImmutable::parse((string) $row->price_expiry_date)->toDateString(),
            );
        }

        return null;
    }

    private function baseUnitPrice(string $unitPrice, ?string $packSize): string
    {
        $pack = $packSize !== null && bccomp($packSize, '0', Quantity::SCALE) > 0
            ? Quantity::of($packSize)
            : '1';

        return bcdiv($unitPrice, $pack, 4);
    }

    public function orderTotal(string $purchaseOrderId): string
    {
        $total = '0';

        $lines = PoLine::query()
            ->where('po_id', $purchaseOrderId)
            ->where('status', '!=', PoLine::STATUS_CANCELLED)
            ->get();

        foreach ($lines as $line) {
            $total = Money::add($total, $line->lineTotal());
        }

        return $total === '0' ? '0.00' : $total;
    }

    private function toSummary(PurchaseOrder $po): PurchaseOrderSummary
    {
        $orderTotal = '0';
        $lines = PoLine::query()
            ->where('po_id', $po->id)
            ->orderBy('line_no')
            ->get()
            ->map(function (PoLine $line) use (&$orderTotal): PoLineSummary {
                $lineTotal = $line->lineTotal();
                if ($line->status !== PoLine::STATUS_CANCELLED) {
                    $orderTotal = Money::add($orderTotal, $lineTotal);
                }

                return new PoLineSummary(
                    lineId: (string) $line->id,
                    lineNo: (int) $line->line_no,
                    lineType: (string) $line->line_type,
                    itemRef: $line->item_ref !== null ? (string) $line->item_ref : null,
                    itemCode: $line->item_code,
                    itemName: $line->item_name,
                    description: $line->description,
                    orderedQty: (string) $line->ordered_qty,
                    uom: (string) $line->uom,
                    unit: (string) $line->unit,
                    packSize: $line->pack_size !== null ? (string) $line->pack_size : null,
                    orderedBaseQty: (string) $line->ordered_base_qty,
                    unitPrice: (string) $line->unit_price,
                    lineTotal: $lineTotal,
                    deliveryLocationRef: $line->delivery_location_ref !== null ? (string) $line->delivery_location_ref : null,
                    requiredDate: $line->required_date?->toDateString(),
                    receivedQty: (string) $line->received_qty,
                    status: (string) $line->status,
                );
            })
            ->all();

        $amendments = PoAmendment::query()
            ->where('po_id', $po->id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (PoAmendment $a): PoAmendmentSummary => new PoAmendmentSummary(
                amendmentId: (string) $a->id,
                sequence: (int) $a->sequence,
                amendmentType: (string) $a->amendment_type,
                poLineId: $a->po_line_id !== null ? (string) $a->po_line_id : null,
                fieldChanged: (string) $a->field_changed,
                previousValue: $a->previous_value,
                newValue: $a->new_value,
                reason: (string) $a->reason,
                amendedByUserId: (string) $a->amended_by_user_id,
                amendedAt: $a->amended_at->toIso8601String(),
            ))
            ->all();

        $receipts = PoReceipt::query()
            ->where('po_id', $po->id)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PoReceipt $r): PoReceiptSummary => new PoReceiptSummary(
                receiptId: (string) $r->id,
                poLineId: $r->po_line_id !== null ? (string) $r->po_line_id : null,
                sourceEventId: (string) $r->source_event_id,
                movementNumber: (string) $r->movement_number,
                itemRef: (string) $r->item_ref,
                batchRef: $r->batch_ref !== null ? (string) $r->batch_ref : null,
                quantity: (string) $r->quantity,
                uom: (string) $r->uom,
                sourceType: (string) $r->source_type,
                receivedAt: $r->received_at->toIso8601String(),
            ))
            ->all();

        $requisition = $po->requisition_id !== null
            ? PurchaseRequisition::query()
                ->select(['requisition_number', 'requesting_department_ref'])
                ->whereKey($po->requisition_id)
                ->first()
            : null;

        return new PurchaseOrderSummary(
            purchaseOrderId: (string) $po->id,
            facilityId: (string) $po->facility_id,
            poNumber: (string) $po->po_number,
            supplierId: (string) $po->supplier_id,
            requisitionId: $po->requisition_id !== null ? (string) $po->requisition_id : null,
            sourcingRfqId: $po->sourcing_rfq_id !== null ? (string) $po->sourcing_rfq_id : null,
            sourcingMethod: $po->sourcing_method,
            contractId: $po->contract_id !== null ? (string) $po->contract_id : null,
            currency: (string) $po->currency,
            priority: (string) $po->priority,
            emergency: (bool) $po->emergency,
            emergencyReason: $po->emergency_reason,
            status: (string) $po->status,
            notes: $po->notes,
            approvedByUserId: $po->approved_by_user_id !== null ? (string) $po->approved_by_user_id : null,
            approvedAt: $po->approved_at?->toIso8601String(),
            issuedByUserId: $po->issued_by_user_id !== null ? (string) $po->issued_by_user_id : null,
            issuedAt: $po->issued_at?->toIso8601String(),
            cancellationReason: $po->cancellation_reason,
            amendmentCount: (int) $po->amendment_count,
            createdByUserId: (string) $po->created_by_user_id,
            orderTotal: $orderTotal === '0' ? '0.00' : $orderTotal,
            lines: array_values($lines),
            amendments: array_values($amendments),
            receipts: array_values($receipts),
            createdAt: $po->created_at?->toIso8601String(),
            requestingDepartmentRef: $requisition?->requesting_department_ref !== null
                ? (string) $requisition->requesting_department_ref
                : null,
            sourceRequisitionNumber: $requisition?->requisition_number !== null
                ? (string) $requisition->requisition_number
                : null,
            closedShortReason: $po->closed_short_reason,
            closedShortAt: $po->closed_short_at?->toIso8601String(),
            costCentreRef: $po->cost_centre_ref,
            budgetRef: $po->budget_ref,
        );
    }
}
