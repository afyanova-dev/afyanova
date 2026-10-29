<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Contracts\Dto\PurchaseRequisitionSummary;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\PurchaseRequisitionReader;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SearchPurchaseRequisitionsHandler
{
    public function __construct(
        private readonly PurchaseRequisitionReader $reader,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    /**
     * @return list<PurchaseRequisitionSummary>
     */
    public function __invoke(SearchPurchaseRequisitions $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchPurchaseRequisitions dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->departmentScope->canAccessWorkspace($user, $query->facilityId)) {
            throw NotFound::aggregate(PurchaseRequisition::class, $query->facilityId);
        }

        return array_values(array_filter(
            $this->reader->searchForFacility($query->facilityId, $query->status),
            fn (PurchaseRequisitionSummary $requisition): bool => $this->departmentScope->canView(
                $user,
                $query->facilityId,
                $requisition->requestingDepartmentRef,
            ),
        ));
    }
}
