<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Contracts\Dto\OpenPurchaseOrderSearchPage;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\PurchaseOrderReader;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SearchReceivablePurchaseOrdersHandler
{
    public function __construct(
        private readonly PurchaseOrderReader $reader,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    public function __invoke(SearchReceivablePurchaseOrders $query): OpenPurchaseOrderSearchPage
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchReceivablePurchaseOrders dispatched without an authenticated user (IC §6.1).');
        }

        if ($this->departmentScope->canViewFacilityWide($user, $query->facilityId)) {
            return $this->reader->searchReceivable(
                $query->facilityId,
                [],
                $query->query,
                $query->page,
                $query->perPage,
            );
        }

        $departmentRefs = $this->departmentScope->departmentsForViewing($user, $query->facilityId);
        if ($departmentRefs === []) {
            throw NotFound::aggregate(PurchaseOrder::class, $query->facilityId);
        }

        return $this->reader->searchReceivable(
            $query->facilityId,
            $departmentRefs,
            $query->query,
            $query->page,
            $query->perPage,
        );
    }
}
