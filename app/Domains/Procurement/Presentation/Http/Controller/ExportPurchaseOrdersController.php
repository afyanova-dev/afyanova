<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Query\GetSuppliers;
use App\Domains\Procurement\Application\Query\SearchPurchaseOrders;
use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;
use App\Domains\Procurement\Contracts\Dto\SupplierSummary;
use App\Domains\Procurement\Presentation\Http\Spreadsheet\ProcurementXlsxDownload;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportPurchaseOrdersController
{
    private const STATUSES = [
        'DRAFT', 'SUBMITTED', 'APPROVED', 'ISSUED',
        'PARTIALLY_RECEIVED', 'FULLY_RECEIVED', 'CLOSED_SHORT', 'CANCELLED',
    ];

    public function __construct(
        private readonly QueryBus $queries,
        private readonly TenantContext $context,
        private readonly ProcurementXlsxDownload $download,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ]);
        $status = isset($filters['status']) && is_string($filters['status']) ? $filters['status'] : null;

        /** @var list<PurchaseOrderSummary> $orders */
        $orders = $this->queries->dispatch(new SearchPurchaseOrders($facilityId, $status));
        /** @var list<SupplierSummary> $suppliers */
        $suppliers = $this->queries->dispatch(new GetSuppliers);
        $supplierNames = [];
        foreach ($suppliers as $supplier) {
            $supplierNames[$supplier->supplierId] = $supplier->name;
        }

        $orderRows = [[
            'PO number', 'Status', 'Supplier', 'Request number', 'Department',
            'Sourcing', 'Priority', 'Currency', 'Total', 'Raised at', 'Approved at', 'Issued at',
            'Cost centre', 'Budget reference',
        ]];
        $lineRows = [[
            'PO number', 'Line', 'Item code', 'Item / description', 'Ordered quantity',
            'Unit', 'Base quantity', 'Base unit', 'Unit price', 'Line total', 'Received', 'Line status',
        ]];

        foreach ($orders as $order) {
            $orderRows[] = [
                $order->poNumber,
                $order->status,
                $supplierNames[$order->supplierId] ?? $order->supplierId,
                $order->sourceRequisitionNumber ?? '',
                $order->requestingDepartmentRef ?? '',
                $order->sourcingMethod ?? '',
                $order->priority,
                $order->currency,
                $order->orderTotal,
                $order->createdAt ?? '',
                $order->approvedAt ?? '',
                $order->issuedAt ?? '',
                $order->costCentreRef ?? '',
                $order->budgetRef ?? '',
            ];

            foreach ($order->lines as $line) {
                $lineRows[] = [
                    $order->poNumber,
                    (string) $line->lineNo,
                    $line->itemCode ?? '',
                    $line->itemName ?? $line->description ?? '',
                    $line->orderedQty,
                    $line->unit,
                    $line->orderedBaseQty,
                    $line->uom,
                    $line->unitPrice,
                    $line->lineTotal,
                    $line->receivedQty,
                    $line->status,
                ];
            }
        }

        $filename = 'purchase-orders-'.now()->format('Y-m-d').'.xlsx';

        return $this->download->response($filename, [
            'Purchase Orders' => $orderRows,
            'Order Lines' => $lineRows,
        ]);
    }
}
