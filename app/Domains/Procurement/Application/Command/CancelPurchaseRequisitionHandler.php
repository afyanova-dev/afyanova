<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Domain\Service\RequisitionLifecycle;

final class CancelPurchaseRequisitionHandler
{
    public function __construct(
        private readonly PurchaseRequisitionRepository $requisitions,
        private readonly RequisitionLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelPurchaseRequisition $command): void
    {
        $requisition = $this->requisitions->getByIdForUpdate($command->requisitionId);

        $this->lifecycle->assertCanCancel((string) $requisition->status);

        $requisition->cancel();

        $this->requisitions->save($requisition);
    }
}
