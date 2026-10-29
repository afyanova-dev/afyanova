<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderLinePage;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\PurchaseOrderReader;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SearchReceivablePurchaseOrderLinesHandler
{
    public function __construct(
        private readonly PurchaseOrderReader $reader,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    public function __invoke(SearchReceivablePurchaseOrderLines $query): OpenPurchaseOrderLinePage
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchReceivablePurchaseOrderLines dispatched without an authenticated user (IC §6.1).');
        }

        if ($this->departmentScope->canViewFacilityWide($user, $query->facilityId)) {
            return $this->reader->receivableLines(
                $query->facilityId,
                [],
                $query->purchaseOrderId,
                $query->query,
                $query->page,
                $query->perPage,
            );
        }

        $departmentRefs = $this->departmentScope->departmentsForViewing($user, $query->facilityId);
        if ($departmentRefs === []) {
            throw NotFound::aggregate(PurchaseOrder::class, $query->purchaseOrderId);
        }

        return $this->reader->receivableLines(
            $query->facilityId,
            $departmentRefs,
            $query->purchaseOrderId,
            $query->query,
            $query->page,
            $query->perPage,
        );
    }
}
