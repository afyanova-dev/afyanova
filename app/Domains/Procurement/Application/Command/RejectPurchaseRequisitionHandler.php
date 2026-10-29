<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Domain\Service\RequisitionLifecycle;

final class RejectPurchaseRequisitionHandler
{
    public function __construct(
        private readonly PurchaseRequisitionRepository $requisitions,
        private readonly RequisitionLifecycle $lifecycle,
    ) {}

    public function __invoke(RejectPurchaseRequisition $command): void
    {
        $requisition = $this->requisitions->getByIdForUpdate($command->requisitionId);

        $this->lifecycle->assertCanReject((string) $requisition->status);

        $requisition->reject($command->actorId, $command->reason);

        $this->requisitions->save($requisition);
    }
}
