<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Contracts\Dto\PurchaseOrderSummary;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\PurchaseOrderReader;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPurchaseOrderHandler
{
    public function __construct(
        private readonly PurchaseOrderReader $reader,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    public function __invoke(GetPurchaseOrder $query): PurchaseOrderSummary
    {
        $summary = $this->reader->summary($query->purchaseOrderId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPurchaseOrder dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->departmentScope->canView(
            $user,
            $summary->facilityId,
            $summary->requestingDepartmentRef,
        )) {
            throw NotFound::aggregate(PurchaseOrder::class, $query->purchaseOrderId);
        }

        return $summary;
    }
}
