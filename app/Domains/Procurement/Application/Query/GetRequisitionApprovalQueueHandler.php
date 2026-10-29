<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\PurchaseRequisitionSummary;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\PurchaseRequisitionReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetRequisitionApprovalQueueHandler
{
    public function __construct(
        private readonly PurchaseRequisitionReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<PurchaseRequisitionSummary>
     */
    public function __invoke(GetRequisitionApprovalQueue $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetRequisitionApprovalQueue dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'procurement.access')) {
            throw NotFound::aggregate(PurchaseRequisition::class, $query->facilityId);
        }

        return $this->reader->approvalQueue($query->facilityId);
    }
}
