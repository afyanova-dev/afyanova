<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Domain\Service\RequisitionLifecycle;

final class ApprovePurchaseRequisitionHandler
{
    public function __construct(
        private readonly PurchaseRequisitionRepository $requisitions,
        private readonly RequisitionLifecycle $lifecycle,
    ) {}

    public function __invoke(ApprovePurchaseRequisition $command): void
    {
        $requisition = $this->requisitions->getByIdForUpdate($command->requisitionId);

        $this->lifecycle->assertCanApprove((string) $requisition->status);

        if ((string) $requisition->created_by_user_id === $command->actorId) {
            throw SelfApprovalNotPermitted::forRequisition($command->requisitionId);
        }

        $requisition->approve($command->actorId);

        $this->requisitions->save($requisition);
    }
}
