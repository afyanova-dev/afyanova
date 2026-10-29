<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Procurement\Application\ApprovalLimitResolver;
use App\Domains\Procurement\Application\PurchaseOrderActionPolicy;
use App\Domains\Procurement\Application\PurchaseRequisitionActionPolicy;
use App\Domains\Procurement\Application\Query\GetPurchaseRequisition;
use App\Domains\Procurement\Application\Query\GetSuppliers;
use App\Domains\Procurement\Application\Query\SearchPurchaseOrders;
use App\Domains\Procurement\Application\Query\SearchPurchaseRequisitions;
use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Contracts\Dto\PoLineSummary;
use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;
use App\Domains\Procurement\Contracts\Dto\PurchaseRequisitionSummary;
use App\Domains\Procurement\Contracts\Dto\RequisitionLineSummary;
use App\Domains\Procurement\Contracts\Dto\SupplierSummary;
use App\Domains\Procurement\Domain\Enum\PurchaseRequisitionStatus;
use App\Domains\Procurement\Domain\ValueObject\SupplierStatus;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Procurement workspace (Shape B). One page, one section at a time
 * (`?section=`): Requests → Purchase orders → Suppliers.
 *
 * Reading needs `procurement.access`; writing is gated by the per-screen
 * permission on the individual POST/PATCH routes, and again by the bus.
 * Requests and orders exist only inside an active facility (they are facility
 * data), while the supplier master is tenant-wide — so a bare URL falls back to
 * Suppliers when there is no active facility.
 *
 * `?section=orders&request=<id>` opens the order panel prefilled from an
 * APPROVED request; that link is what carries the request's department into the
 * purchase order (and therefore into the approval-limit rule).
 */
final class ProcurementWorkspaceController
{
    /** @var list<string> */
    private const SECTIONS = ['requests', 'orders', 'suppliers'];

    public function __construct(
        private readonly QueryBus $queries,
        private readonly InventoryApi $inventory,
        private readonly OrganizationApi $organization,
        private readonly ApprovalLimitResolver $limits,
        private readonly PurchaseOrderActionPolicy $orderActions,
        private readonly PurchaseRequisitionActionPolicy $requestActions,
        private readonly RequisitionDepartmentScope $departmentScope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $section = $this->section($request);
        $facilityId = $this->context->facilityId();
        abort_unless($user instanceof User, 401);
        abort_unless(
            Gate::allows('procurement.access')
                || $this->departmentScope->canAccessWorkspace($user, $facilityId),
            403,
        );
        $facilityContextRequired = in_array($section, ['requests', 'orders'], true)
            && ($facilityId === null || $facilityId === '');

        $props = [
            'section' => $section,
            'facilityContextRequired' => $facilityContextRequired,
            'suppliers' => in_array($section, ['orders', 'suppliers'], true)
                ? $this->supplierRows()
                : [],
            'supplierStatuses' => SupplierStatus::ALL,
            'canManageSuppliers' => $user->can('procurement.suppliers.manage'),
            'canCreateOrders' => $user->can('procurement.orders.create'),
            'canCreateRequests' => $user->can('procurement.requisitions.create'),
            'canCreateForAnyDepartment' => false,
            'currentUserId' => (string) $user->id,
            'purchaseOrders' => [],
            'purchaseRequisitions' => [],
            'departments' => [],
            'items' => [],
            'effectiveApprovalLimit' => null,
            'initialRequest' => null,
            'orderableRequests' => [],
        ];

        if ($section === 'requests' && ! $facilityContextRequired) {
            $props = array_merge($props, $this->requestProps($user));
        }

        if ($section === 'orders' && ! $facilityContextRequired) {
            $props = array_merge($props, $this->orderProps($user, $request));
        }

        return Inertia::render('Procurement/Index', $props);
    }

    private function section(Request $request): string
    {
        $section = $request->query('section');

        if (is_string($section) && in_array($section, self::SECTIONS, true)) {
            return $section;
        }

        // Requests and orders need a facility; the supplier master does not.
        return $this->context->facilityId() === null ? 'suppliers' : 'requests';
    }

    private function requireFacilityId(): string
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');

        return $facilityId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function supplierRows(): array
    {
        /** @var list<SupplierSummary> $suppliers */
        $suppliers = $this->queries->dispatch(new GetSuppliers);

        return array_map(static fn (SupplierSummary $supplier): array => [
            'id' => $supplier->supplierId,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'registrationNumber' => $supplier->registrationNumber,
            'licenceNumber' => $supplier->licenceNumber,
            'licenceAuthority' => $supplier->licenceAuthority,
            'licenceExpiryDate' => $supplier->licenceExpiryDate,
            'licenceExpiryStatus' => $supplier->licenceExpiryStatus,
            'contactName' => $supplier->contactName,
            'contactEmail' => $supplier->contactEmail,
            'contactPhone' => $supplier->contactPhone,
            'address' => $supplier->address,
            'taxIdentifier' => $supplier->taxIdentifier,
            'paymentTerms' => $supplier->paymentTerms,
            'categories' => $supplier->categories,
            'status' => $supplier->status,
            'statusReason' => $supplier->statusReason,
            'allowedStatuses' => $supplier->allowedStatuses,
        ], $suppliers);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestProps(?User $user): array
    {
        $facilityId = $this->requireFacilityId();
        $allDepartments = $this->departments($facilityId);
        $departmentNames = array_column($allDepartments, 'name', 'id');
        $departments = $user instanceof User
            ? $this->departmentScope->departmentsForCreation($user, $facilityId)
            : [];
        $canCreateForAnyDepartment = $user instanceof User
            && $this->departmentScope->canCreateForAnyDepartment($user, $facilityId);

        /** @var list<PurchaseRequisitionSummary> $requisitions */
        $requisitions = $this->queries->dispatch(new SearchPurchaseRequisitions($facilityId));

        return [
            'purchaseRequisitions' => array_map(
                fn (PurchaseRequisitionSummary $requisition): array => $this->requisitionRow(
                    $requisition,
                    $user,
                    $departmentNames,
                ),
                $requisitions,
            ),
            'departments' => $departments,
            'canCreateRequests' => $canCreateForAnyDepartment || $departments !== [],
            'canCreateForAnyDepartment' => $canCreateForAnyDepartment,
            // The request form picks an item exactly like an order line does.
            'items' => $this->itemRows(),
        ];
    }

    /**
     * Everything the purchase-order screen needs. Requires an active facility:
     * an order always belongs to one.
     *
     * @return array<string, mixed>
     */
    private function orderProps(?User $user, Request $request): array
    {
        $facilityId = $this->requireFacilityId();
        $departments = $this->departments($facilityId);

        /** @var list<PurchaseOrderSummary> $orders */
        $orders = $this->queries->dispatch(new SearchPurchaseOrders($facilityId));
        /** @var list<PurchaseRequisitionSummary> $approvedRequisitions */
        $approvedRequisitions = $this->queries->dispatch(new SearchPurchaseRequisitions(
            $facilityId,
            PurchaseRequisitionStatus::Approved->value,
        ));
        $orderableRequests = array_values(array_map(
            fn (PurchaseRequisitionSummary $requisition): array => $this->requestForOrder($requisition, $departments),
            array_filter(
                $approvedRequisitions,
                fn (PurchaseRequisitionSummary $requisition): bool => $this->requestActions->canBeOrdered($requisition),
            ),
        ));

        return [
            'purchaseOrders' => array_map(
                fn (PurchaseOrderSummary $order): array => $this->orderRow($order, $user),
                $orders,
            ),
            'items' => $this->itemRows(),
            // The limit a DIRECT order is judged at (no requisition, so no
            // department). A department's own limit is applied per order and is
            // already reflected in `selfApprovalPermitted`.
            'effectiveApprovalLimit' => $this->limits->resolve($facilityId, null),
            'initialRequest' => $this->initialRequest($request, $facilityId, $departments),
            'orderableRequests' => $orderableRequests,
        ];
    }

    /**
     * The APPROVED request the order panel should open prefilled with, if the
     * query string names one that belongs to this facility. Anything else is
     * ignored (the buyer sees an empty panel) rather than half-trusted.
     *
     * @param  list<array{id: string, name: string, code: string}>  $departments
     * @return array<string, mixed>|null
     */
    private function initialRequest(Request $request, string $facilityId, array $departments): ?array
    {
        $requisitionId = $request->query('request');

        if (! is_string($requisitionId) || ! Str::isUuid($requisitionId)) {
            return null;
        }

        try {
            $requisition = $this->queries->dispatch(new GetPurchaseRequisition($requisitionId, $facilityId));
        } catch (NotFound|InvalidArgumentException) {
            return null;
        }

        if ($requisition->facilityId !== $facilityId || ! $this->requestActions->canBeOrdered($requisition)) {
            return null;
        }

        return $this->requestForOrder($requisition, $departments);
    }

    /**
     * @param  list<array{id: string, name: string, code: string}>  $departments
     * @return array<string, mixed>
     */
    private function requestForOrder(PurchaseRequisitionSummary $requisition, array $departments): array
    {
        $lines = [];
        $unorderableLineCount = 0;

        foreach ($requisition->lines as $line) {
            if ($line->lineType !== 'STOCK' || $line->itemRef === null) {
                $unorderableLineCount++;

                continue;
            }

            $lines[] = [
                'itemId' => $line->itemRef,
                'itemCode' => $line->itemCode,
                'itemName' => $line->itemName,
                'quantity' => $line->quantity,
                'uom' => $line->uom,
                'unit' => $line->unit,
                'packSize' => $line->packSize,
            ];
        }

        $departmentRef = $requisition->requestingDepartmentRef;
        $departmentNames = array_column($departments, 'name', 'id');

        return [
            'id' => $requisition->requisitionId,
            'requisitionNumber' => $requisition->requisitionNumber,
            'departmentRef' => $departmentRef,
            'departmentName' => $departmentRef !== null ? ($departmentNames[$departmentRef] ?? null) : null,
            'priority' => $requisition->priority,
            'reason' => $requisition->reason,
            'costCentreRef' => $requisition->costCentreRef,
            'budgetRef' => $requisition->budgetRef,
            'lines' => $lines,
            'unorderableLineCount' => $unorderableLineCount,
        ];
    }

    /**
     * The active catalogue both the request and order forms pick from.
     *
     * @return list<array{id: string, code: string, name: string, uom: string, category: string|null, itemType: string}>
     */
    private function itemRows(): array
    {
        /** @var list<InventoryItemRef> $items */
        $items = $this->inventory->getItems(null, true);

        return array_map(static fn (InventoryItemRef $item): array => [
            'id' => $item->itemId,
            'code' => $item->code,
            'name' => $item->name,
            'uom' => $item->uom,
            'packUnit' => $item->packUnit,
            'packSize' => $item->packSize,
            'category' => $item->category,
            'itemType' => $item->itemType,
        ], $items);
    }

    /**
     * @return list<array{id: string, name: string, code: string}>
     */
    private function departments(string $facilityId): array
    {
        $structure = $this->organization->getFacilityStructure($facilityId);

        if ($structure === null) {
            return [];
        }

        return array_map(static fn (array $department): array => [
            'id' => (string) ($department['id'] ?? ''),
            'name' => (string) ($department['name'] ?? ''),
            'code' => (string) ($department['code'] ?? ''),
        ], $structure->departments);
    }

    /**
     * @param  array<string, string>  $departmentNames
     * @return array<string, mixed>
     */
    private function requisitionRow(
        PurchaseRequisitionSummary $requisition,
        ?User $user,
        array $departmentNames,
    ): array {
        $departmentRef = $requisition->requestingDepartmentRef;

        return [
            'id' => $requisition->requisitionId,
            'requisitionNumber' => $requisition->requisitionNumber,
            'departmentRef' => $departmentRef,
            'departmentName' => $departmentRef !== null ? ($departmentNames[$departmentRef] ?? null) : null,
            'status' => $requisition->status,
            'priority' => $requisition->priority,
            'reason' => $requisition->reason,
            'estimatedValue' => $requisition->estimatedValue,
            'costCentreRef' => $requisition->costCentreRef,
            'budgetRef' => $requisition->budgetRef,
            'emergency' => $requisition->emergency,
            'emergencyReason' => $requisition->emergencyReason,
            'reviewedByUserId' => $requisition->reviewedByUserId,
            'reviewedAt' => $requisition->reviewedAt,
            'rejectionReason' => $requisition->rejectionReason,
            'createdByUserId' => $requisition->createdByUserId,
            'createdAt' => $requisition->createdAt,
            'hasActivePurchaseOrder' => $requisition->hasActivePurchaseOrder,
            'allowedActions' => $user !== null
                ? $this->requestActions->allowedFor($requisition, $user)
                : [],
            'lines' => array_map(static fn (RequisitionLineSummary $line): array => [
                'id' => $line->lineId,
                'lineNo' => $line->lineNo,
                'itemRef' => $line->itemRef,
                'itemCode' => $line->itemCode,
                'itemName' => $line->itemName,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'uom' => $line->uom,
                'unit' => $line->unit,
                'packSize' => $line->packSize,
                'requiredDate' => $line->requiredDate,
                'status' => $line->status,
            ], $requisition->lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(PurchaseOrderSummary $order, ?User $user): array
    {
        return [
            'id' => $order->purchaseOrderId,
            'poNumber' => $order->poNumber,
            'supplierId' => $order->supplierId,
            'requisitionId' => $order->requisitionId,
            'sourcingMethod' => $order->sourcingMethod,
            'status' => $order->status,
            'currency' => $order->currency,
            'priority' => $order->priority,
            'emergency' => $order->emergency,
            'orderTotal' => $order->orderTotal,
            'notes' => $order->notes,
            'costCentreRef' => $order->costCentreRef,
            'budgetRef' => $order->budgetRef,
            'createdByUserId' => $order->createdByUserId,
            'approvedByUserId' => $order->approvedByUserId,
            'issuedByUserId' => $order->issuedByUserId,
            'cancellationReason' => $order->cancellationReason,
            'closedShortReason' => $order->closedShortReason,
            'closedShortAt' => $order->closedShortAt,
            'createdAt' => $order->createdAt,
            'amendmentCount' => $order->amendmentCount,
            'selfApprovalPermitted' => $this->orderActions->selfApprovalFor($order),
            'allowedActions' => $user !== null ? $this->orderActions->allowedFor($order, $user) : [],
            'lines' => array_map(static fn (PoLineSummary $line): array => [
                'id' => $line->lineId,
                'lineNo' => $line->lineNo,
                'itemCode' => $line->itemCode,
                'itemName' => $line->itemName,
                'description' => $line->description,
                'orderedQty' => $line->orderedQty,
                'receivedQty' => $line->receivedQty,
                'uom' => $line->uom,
                'unit' => $line->unit,
                'packSize' => $line->packSize,
                'unitPrice' => $line->unitPrice,
                'lineTotal' => $line->lineTotal,
                'status' => $line->status,
            ], $order->lines),
        ];
    }
}
