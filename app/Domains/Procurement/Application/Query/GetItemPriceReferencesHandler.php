<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Contracts\Dto\ItemPriceReference;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\PurchaseOrderReader;
use App\Models\User;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetItemPriceReferencesHandler
{
    public function __construct(
        private readonly PurchaseOrderReader $reader,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    public function __invoke(GetItemPriceReferences $query): ItemPriceReference
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetItemPriceReferences dispatched without an authenticated user (IC §6.1).');
        }

        if ($this->departmentScope->canViewFacilityWide($user, $query->facilityId)) {
            $departmentRefs = [];
        } else {
            $departmentRefs = $this->departmentScope->departmentsForViewing($user, $query->facilityId);
            if ($departmentRefs === []) {
                throw NotFound::aggregate(PurchaseOrder::class, $query->facilityId);
            }
        }

        return $this->reader->itemPriceReference(
            $query->facilityId,
            $departmentRefs,
            $query->supplierId,
            $query->itemId,
        );
    }
}
