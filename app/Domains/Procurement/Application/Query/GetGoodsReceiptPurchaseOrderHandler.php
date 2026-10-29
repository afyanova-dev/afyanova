<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Contracts\Dto\GoodsReceiptPurchaseOrderLineRef;
use App\Domains\Procurement\Contracts\Dto\GoodsReceiptPurchaseOrderRef;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\PurchaseOrderReader;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetGoodsReceiptPurchaseOrderHandler
{
    public function __construct(
        private readonly PurchaseOrderReader $reader,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    public function __invoke(GetGoodsReceiptPurchaseOrder $query): ?GoodsReceiptPurchaseOrderRef
    {
        $summary = $this->reader->findSummaryByNumber($query->facilityId, $query->poNumber);
        if ($summary === null) {
            return null;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetGoodsReceiptPurchaseOrder dispatched without an authenticated user (IC §6.1).');
        }
        if (! $this->departmentScope->canView($user, $summary->facilityId, $summary->requestingDepartmentRef)) {
            throw NotFound::aggregate(PurchaseOrder::class, $summary->purchaseOrderId);
        }

        $lines = [];
        foreach ($summary->lines as $line) {
            $lines[] = new GoodsReceiptPurchaseOrderLineRef(
                lineId: $line->lineId,
                lineType: $line->lineType,
                itemRef: $line->itemRef,
                unit: $line->unit,
                unitPrice: $line->unitPrice,
                status: $line->status,
            );
        }

        return new GoodsReceiptPurchaseOrderRef(
            purchaseOrderId: $summary->purchaseOrderId,
            facilityId: $summary->facilityId,
            poNumber: $summary->poNumber,
            currency: $summary->currency,
            status: $summary->status,
            lines: $lines,
        );
    }
}
