<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Application\ProcurementContextResolver;
use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Exception\ContractCeilingExceeded;
use App\Domains\Procurement\Domain\Exception\ContractLineInvalid;
use App\Domains\Procurement\Domain\Exception\ContractNotUsable;
use App\Domains\Procurement\Domain\Exception\ContractSupplierMismatch;
use App\Domains\Procurement\Domain\Exception\DuplicateDirectPurchaseOrder;
use App\Domains\Procurement\Domain\Exception\ProcurementContextMismatch;
use App\Domains\Procurement\Domain\Exception\RequisitionAlreadyOrdered;
use App\Domains\Procurement\Domain\Exception\RequisitionNotApprovedForOrder;
use App\Domains\Procurement\Domain\Exception\RfqAwardInvalid;
use App\Domains\Procurement\Domain\Exception\SupplierNotOrderable;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\Model\RfqAward;
use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Domain\Quantity;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\SupplierRepository;
use App\Domains\Procurement\Infrastructure\Sequence\PurchaseOrderNumberSequence;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates `CreatePurchaseOrder` (ADR-171 D-H).
 *
 * Flow: ADR-133 per-facility refinement → emergency composite check → supplier
 * must be ACTIVE (`SupplierNotOrderable`) → optional requisition guard rails
 * (APPROVED + same facility) → the lines come from `$command->lines` (DIRECT /
 * REQUISITION), OR, when `$command->rfqId` is set, from `pcr_rfq_awards` for
 * `(rfqId, supplierId)` on an AWARDED RFQ (ADR-173 D-N), OR, when
 * `$command->contractId` is set, from `pcr_contract_lines` on an ACTIVE +
 * unexpired contract for the same supplier (ADR-176 D-D — the agreed contract
 * price wins, the per-line `quantity_ceiling` is enforced per-PO; the contract
 * row is locked `FOR UPDATE`) → allocate `PO-%08d` → create DRAFT → save →
 * write `pcr_po_lines` → (contract branch only) re-assert the CUMULATIVE
 * contract-line ceiling over the just-written lines (ADR-177 INV-33a) — one txn.
 *
 * `PurchaseOrderRepository` is the first repository parameter so the bus
 * resolves `PurchaseOrderPolicy`.
 */
final class CreatePurchaseOrderHandler
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly SupplierRepository $suppliers,
        private readonly PurchaseRequisitionRepository $requisitions,
        private readonly RfqRepository $rfqs,
        private readonly ContractRepository $contracts,
        private readonly ProcurementContextResolver $resolver,
        private readonly PurchaseOrderNumberSequence $poNumbers,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(CreatePurchaseOrder $command): void
    {
        $this->resolver->assertActorScopedToFacility(
            $command->facilityId,
            'procurement.orders.create',
            $command->purchaseOrderId,
        );

        if ($command->emergency) {
            $this->assertEmergencyAuthorised($command->facilityId, $command->purchaseOrderId);
        }

        $supplier = $this->suppliers->getById($command->supplierId);
        if (! $supplier->isOrderable()) {
            throw SupplierNotOrderable::status($command->supplierId, (string) $supplier->status);
        }

        $costCentreRef = $command->costCentreRef;
        $budgetRef = $command->budgetRef;

        if ($command->requisitionId !== null) {
            $requisition = $this->requisitions->getByIdForUpdate($command->requisitionId);
            if ((string) $requisition->status !== PurchaseRequisition::STATUS_APPROVED) {
                throw RequisitionNotApprovedForOrder::status($command->requisitionId, (string) $requisition->status);
            }
            if ((string) $requisition->facility_id !== $command->facilityId) {
                throw ProcurementContextMismatch::requisitionFacility(
                    $command->facilityId,
                    $command->requisitionId,
                    (string) $requisition->facility_id,
                );
            }

            $hasActivePurchaseOrder = PurchaseOrder::query()
                ->where('requisition_id', $command->requisitionId)
                ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
                ->exists();
            if ($hasActivePurchaseOrder) {
                throw RequisitionAlreadyOrdered::forRequisition($command->requisitionId);
            }

            // P2-01 — the request's reporting references travel with the order
            // unless the buyer explicitly supplied different ones.
            if ($costCentreRef === null || trim($costCentreRef) === '') {
                $costCentreRef = $requisition->cost_centre_ref;
            }
            if ($budgetRef === null || trim($budgetRef) === '') {
                $budgetRef = $requisition->budget_ref;
            }
        }

        // `sourcing_method` precedence (ADR-176 D-D): CONTRACT > RFQ > REQUISITION
        // > DIRECT. `rfqId` / `contractId` are mutually exclusive at the command.
        if ($command->contractId !== null) {
            $resolved = $this->resolveFromContract($command);
            $sourcingMethod = PurchaseOrder::SOURCING_METHOD_CONTRACT;
        } elseif ($command->rfqId !== null) {
            $resolved = $this->resolveFromAward($command);
            $sourcingMethod = PurchaseOrder::SOURCING_METHOD_RFQ;
        } else {
            $resolved = [];
            foreach ($command->lines as $index => $line) {
                $resolved[] = $this->resolver->resolveLine($line, $index + 1);
            }
            $sourcingMethod = $command->requisitionId !== null
                ? PurchaseOrder::SOURCING_METHOD_REQUISITION
                : PurchaseOrder::SOURCING_METHOD_DIRECT;
        }

        if ($command->requisitionId === null
            && $command->rfqId === null
            && $command->contractId === null) {
            $this->guardAgainstDuplicateDirectOrder($command, $resolved);
        }

        $tenantId = $this->context->tenantId();

        $order = PurchaseOrder::create(
            id: $command->purchaseOrderId,
            tenantId: $tenantId,
            facilityId: $command->facilityId,
            poNumber: $this->poNumbers->next($tenantId),
            supplierId: $command->supplierId,
            requisitionId: $command->requisitionId,
            currency: $command->currency,
            priority: strtoupper(trim($command->priority)),
            createdByUserId: $command->actorId,
            emergency: $command->emergency,
            emergencyReason: $command->emergencyReason,
            notes: $command->notes,
            sourcingRfqId: $command->rfqId,
            sourcingMethod: $sourcingMethod,
            contractId: $command->contractId,
            costCentreRef: $costCentreRef,
            budgetRef: $budgetRef,
        );

        $this->orders->save($order);

        foreach ($resolved as $index => $line) {
            PoLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $command->facilityId,
                'po_id' => $command->purchaseOrderId,
                'line_no' => $index + 1,
                'line_type' => $line['lineType'],
                'item_ref' => $line['itemRef'],
                'contract_line_id' => $line['contractLineId'] ?? null,
                'item_code' => $line['itemCode'],
                'item_name' => $line['itemName'],
                'description' => $line['description'],
                'ordered_qty' => $line['quantity'],
                'uom' => $line['uom'],
                'unit' => $line['unit'] ?? $line['uom'],
                'pack_size' => $line['packSize'] ?? null,
                'unit_price' => Money::of($line['unitPrice'] ?? '0'),
                'delivery_location_ref' => $line['deliveryLocationRef'],
                'required_date' => $line['requiredDate'] !== null ? CarbonImmutable::parse($line['requiredDate']) : null,
                'received_qty' => 0,
                'status' => PoLine::STATUS_OPEN,
            ]);
        }

        // ADR-177 INV-33a — the cumulative contract-line ceiling. The candidate
        // PO lines are now written; the `pcr_contracts` row is locked (taken in
        // `resolveFromContract`). Recompute the WHOLE Σ per contract line and
        // roll the transaction back if any ceiling is breached.
        if ($command->contractId !== null) {
            $this->assertContractCeilingsNotExceeded($resolved);
        }
    }

    /**
     * P2-03 — refuse an exact repeat of an ACTIVE direct order at the same
     * facility/supplier unless the buyer records why the repeat is genuine.
     *
     * "Exact" is the multiset of line type, item, base quantity, entered unit,
     * pack size and unit price. The check deliberately ignores notes/dates so a
     * copy-paste or double-submit is caught, while a terminal order
     * (FULLY_RECEIVED / CANCELLED / CLOSED_SHORT) never blocks a later genuine
     * reorder.
     *
     * @param  list<array<string, mixed>>  $resolved
     */
    private function guardAgainstDuplicateDirectOrder(CreatePurchaseOrder $command, array $resolved): void
    {
        $expected = array_map(fn (array $line): string => $this->resolvedLineSignature($line), $resolved);
        sort($expected);

        /** @var list<PurchaseOrder> $candidates */
        $candidates = PurchaseOrder::query()
            ->where('facility_id', $command->facilityId)
            ->where('supplier_id', $command->supplierId)
            ->whereNull('requisition_id')
            ->whereNull('sourcing_rfq_id')
            ->whereNull('contract_id')
            ->whereIn('status', [
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_SUBMITTED,
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_ISSUED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get();

        if ($candidates === []) {
            return;
        }

        /** @var array<string, list<PoLine>> $linesByOrder */
        $linesByOrder = [];

        foreach (PoLine::query()
            ->whereIn('po_id', $candidates->modelKeys())
            ->where('status', '!=', PoLine::STATUS_CANCELLED)
            ->get() as $line) {
            $linesByOrder[(string) $line->po_id][] = $line;
        }

        foreach ($candidates as $candidate) {
            $existing = array_map(
                fn (PoLine $line): string => $this->existingLineSignature($line),
                $linesByOrder[(string) $candidate->id] ?? [],
            );

            if (count($existing) !== count($expected)) {
                continue;
            }

            sort($existing);

            if ($existing !== $expected) {
                continue;
            }

            $reason = $command->duplicateOverrideReason !== null
                ? trim($command->duplicateOverrideReason)
                : '';

            if ($reason === '') {
                throw DuplicateDirectPurchaseOrder::matches(
                    (string) $candidate->po_number,
                    (string) $candidate->id,
                );
            }

            $this->audit->record(
                action: 'procurement.order_duplicate_overridden',
                actorId: $command->actorId,
                targetType: 'purchase_order',
                targetId: $command->purchaseOrderId,
                reason: $reason,
                metadata: [
                    'facility_id' => $command->facilityId,
                    'supplier_id' => $command->supplierId,
                    'duplicate_of_purchase_order_id' => (string) $candidate->id,
                    'duplicate_po_number' => (string) $candidate->po_number,
                ],
            );

            return;
        }
    }

    /** @param array<string, mixed> $line */
    private function resolvedLineSignature(array $line): string
    {
        return implode("\x1f", [
            (string) $line['lineType'],
            (string) ($line['itemRef'] ?? ''),
            trim((string) ($line['description'] ?? '')),
            Quantity::of((string) $line['orderedQuantity']),
            (string) $line['unit'],
            $line['packSize'] !== null ? Quantity::of((string) $line['packSize']) : '',
            Money::of((string) ($line['unitPrice'] ?? '0')),
        ]);
    }

    private function existingLineSignature(PoLine $line): string
    {
        return implode("\x1f", [
            (string) $line->line_type,
            (string) ($line->item_ref ?? ''),
            trim((string) ($line->description ?? '')),
            Quantity::of((string) $line->ordered_base_qty),
            (string) $line->unit,
            $line->pack_size !== null ? Quantity::of((string) $line->pack_size) : '',
            Money::of((string) $line->unit_price),
        ]);
    }

    /**
     * INV-33a (ADR-177 D-D) — for each DISTINCT `contract_line_id` among the
     * just-written PO lines whose contract line carries a `quantity_ceiling`,
     * Σ `ordered_base_qty` over ALL non-CANCELLED PO lines for that contract line
     * (including the ones just written) must be ≤ the ceiling.
     *
     * @param  list<array<string, mixed>>  $resolved
     */
    private function assertContractCeilingsNotExceeded(array $resolved): void
    {
        $contractLineIds = [];
        foreach ($resolved as $line) {
            $clid = $line['contractLineId'] ?? null;
            if (is_string($clid)) {
                $contractLineIds[$clid] = true;
            }
        }

        if ($contractLineIds === []) {
            return;
        }

        /** @var array<string, ContractLine> $contractLines */
        $contractLines = ContractLine::query()
            ->whereIn('id', array_keys($contractLineIds))
            ->get()
            ->keyBy(static fn (ContractLine $l): string => (string) $l->id)
            ->all();

        foreach (array_keys($contractLineIds) as $contractLineId) {
            $contractLine = $contractLines[$contractLineId] ?? null;
            if ($contractLine === null || $contractLine->quantity_ceiling === null) {
                continue;
            }

            $committed = $this->contracts->committedQuantityForContractLine($contractLineId);

            if (! Quantity::gte((string) $contractLine->quantity_ceiling, $committed)) {
                throw ContractCeilingExceeded::forContractLine(
                    $contractLineId,
                    $committed,
                    (string) $contractLine->quantity_ceiling,
                );
            }
        }
    }

    /**
     * Seed the PO lines from `pcr_rfq_awards` for `(rfqId, supplierId)` on an
     * AWARDED RFQ (ADR-173 D-N). Returns rows in the same shape
     * `ProcurementContextResolver::resolveLine` produces.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveFromAward(CreatePurchaseOrder $command): array
    {
        /** @var string $rfqId */
        $rfqId = $command->rfqId;
        $rfq = $this->rfqs->getById($rfqId);

        if ((string) $rfq->status !== Rfq::STATUS_AWARDED) {
            throw RfqAwardInvalid::rfqNotAwarded($rfqId, (string) $rfq->status);
        }
        if ((string) $rfq->facility_id !== $command->facilityId) {
            throw ProcurementContextMismatch::requisitionFacility($command->facilityId, $rfqId, (string) $rfq->facility_id);
        }

        $awards = RfqAward::query()
            ->where('rfq_id', $rfqId)
            ->where('supplier_id', $command->supplierId)
            ->orderBy('rfq_line_id')
            ->get();

        if ($awards->isEmpty()) {
            throw RfqAwardInvalid::noAwardForSupplier($rfqId, $command->supplierId);
        }

        $rfqLines = RfqLine::query()
            ->where('rfq_id', $rfqId)
            ->get()
            ->keyBy(static fn (RfqLine $l): string => (string) $l->id);

        $resolved = [];
        foreach ($awards as $award) {
            /** @var RfqLine|null $rfqLine */
            $rfqLine = $rfqLines->get((string) $award->rfq_line_id);
            $resolved[] = [
                'lineType' => $rfqLine !== null ? (string) $rfqLine->line_type : PoLine::LINE_TYPE_STOCK,
                'itemRef' => $rfqLine !== null && $rfqLine->item_ref !== null ? (string) $rfqLine->item_ref : null,
                'itemCode' => $rfqLine?->item_code,
                'itemName' => $rfqLine?->item_name,
                'description' => $rfqLine?->description,
                'quantity' => (string) $award->awarded_qty,
                'orderedQuantity' => (string) $award->awarded_qty,
                'uom' => $rfqLine !== null ? (string) $rfqLine->uom : 'EACH',
                'unit' => $rfqLine !== null ? (string) $rfqLine->uom : 'EACH',
                'packSize' => null,
                'requiredDate' => $rfqLine !== null ? $rfqLine->required_date?->toDateString() : null,
                'unitPrice' => (string) $award->unit_price,
                'deliveryLocationRef' => null,
            ];
        }

        return $resolved;
    }

    /**
     * Seed the PO lines from `pcr_contract_lines` for the cited contract
     * (ADR-176 D-D). The contract must be ACTIVE + unexpired (§194 / Invariant 32
     * — check-at-use) and belong to `$command->supplierId`. Each PO line cites a
     * `contractLineId` on the contract; the ordered `quantity` must not exceed
     * that line's `quantity_ceiling` when set (§198 / Invariant 33 — per-PO). The
     * agreed contract price wins (ADR-176 D4). Returns rows in the same shape
     * `ProcurementContextResolver::resolveLine` produces, plus `contractLineId`.
     *
     * The contract is loaded `FOR UPDATE` (ADR-177 D-D) — the `pcr_contracts`
     * row lock is the concurrency boundary for INV-33a and is held for the rest
     * of the bus transaction.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveFromContract(CreatePurchaseOrder $command): array
    {
        /** @var string $contractId */
        $contractId = $command->contractId;
        $contract = $this->contracts->getByIdForUpdate($contractId);

        if (! $contract->isUsable()) {
            if ((string) $contract->status !== Contract::STATUS_ACTIVE) {
                throw ContractNotUsable::status($contractId, (string) $contract->status);
            }

            throw ContractNotUsable::expired($contractId, $contract->expiry_date->toDateString());
        }

        if ((string) $contract->supplier_id !== $command->supplierId) {
            throw ContractSupplierMismatch::forContract($contractId, $command->supplierId, (string) $contract->supplier_id);
        }

        $contractLines = ContractLine::query()
            ->where('contract_id', $contractId)
            ->get()
            ->keyBy(static fn (ContractLine $l): string => (string) $l->id);

        $resolved = [];
        foreach ($command->lines as $line) {
            $contractLineId = (string) $line['contractLineId'];

            /** @var ContractLine|null $contractLine */
            $contractLine = $contractLines->get($contractLineId);
            if ($contractLine === null) {
                throw ContractLineInvalid::notOnContract($contractLineId);
            }

            $quantity = Quantity::of((string) $line['quantity']);

            if ($contractLine->quantity_ceiling !== null
                && ! Quantity::gte((string) $contractLine->quantity_ceiling, $quantity)) {
                throw ContractLineInvalid::ceilingExceeded(
                    $contractLineId,
                    $quantity,
                    (string) $contractLine->quantity_ceiling,
                );
            }

            $resolved[] = [
                'lineType' => (string) $contractLine->line_type,
                'contractLineId' => (string) $contractLine->id,
                'itemRef' => $contractLine->item_ref !== null ? (string) $contractLine->item_ref : null,
                'itemCode' => $contractLine->item_code,
                'itemName' => $contractLine->item_name,
                'description' => $contractLine->description,
                'quantity' => $quantity,
                'orderedQuantity' => $quantity,
                'uom' => (string) $contractLine->uom,
                'unit' => (string) $contractLine->uom,
                'packSize' => null,
                'requiredDate' => isset($line['requiredDate']) && trim((string) $line['requiredDate']) !== ''
                    ? (string) $line['requiredDate']
                    : null,
                'unitPrice' => (string) $contractLine->agreed_unit_price,
                'deliveryLocationRef' => isset($line['deliveryLocationRef']) && trim((string) $line['deliveryLocationRef']) !== ''
                    ? (string) $line['deliveryLocationRef']
                    : null,
            ];
        }

        return $resolved;
    }

    private function assertEmergencyAuthorised(string $facilityId, string $subjectId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('CreatePurchaseOrder dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('procurement.emergency.manage', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(PurchaseOrder::class, $subjectId);
        }
    }
}
