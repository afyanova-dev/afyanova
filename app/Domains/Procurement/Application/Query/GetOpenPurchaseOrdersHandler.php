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

final class GetOpenPurchaseOrdersHandler
{
    public function __construct(
        private readonly PurchaseOrderReader $reader,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    /**
     * @return list<PurchaseOrderSummary>
     */
    public function __invoke(GetOpenPurchaseOrders $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetOpenPurchaseOrders dispatched without an authenticated user (IC §6.1).');
        }

        if ($this->departmentScope->canViewFacilityWide($user, $query->facilityId)) {
            return $this->reader->openForFacility($query->facilityId);
        }

        $departmentRefs = $this->departmentScope->departmentsForViewing($user, $query->facilityId);
        if ($departmentRefs === []) {
            throw NotFound::aggregate(PurchaseOrder::class, $query->facilityId);
        }

        return $this->reader->openForFacilityAndDepartments($query->facilityId, $departmentRefs);
    }
}
