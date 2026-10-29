<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\PurchaseRequisitionRepository;
use App\Domains\Procurement\Domain\Service\RequisitionLifecycle;

final class SubmitPurchaseRequisitionHandler
{
    public function __construct(
        private readonly PurchaseRequisitionRepository $requisitions,
        private readonly RequisitionLifecycle $lifecycle,
    ) {}

    public function __invoke(SubmitPurchaseRequisition $command): void
    {
        $requisition = $this->requisitions->getByIdForUpdate($command->requisitionId);

        $this->lifecycle->assertCanSubmit((string) $requisition->status);

        $requisition->submit();

        $this->requisitions->save($requisition);
    }
}
