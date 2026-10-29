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

final class GetPurchaseRequisitionHandler
{
    public function __construct(
        private readonly PurchaseRequisitionReader $reader,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    public function __invoke(GetPurchaseRequisition $query): PurchaseRequisitionSummary
    {
        $summary = $this->reader->summary($query->requisitionId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPurchaseRequisition dispatched without an authenticated user (IC §6.1).');
        }

        if ($summary->facilityId !== $query->facilityId
            || ! $this->departmentScope->canView($user, $summary->facilityId, $summary->requestingDepartmentRef)) {
            throw NotFound::aggregate(PurchaseRequisition::class, $query->requisitionId);
        }

        return $summary;
    }
}
