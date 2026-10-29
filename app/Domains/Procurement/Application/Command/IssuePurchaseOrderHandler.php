<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Application\PurchaseOrderSelfApprovalPolicy;
use App\Domains\Procurement\Domain\Exception\SelfIssuanceNotPermitted;
use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Domains\Procurement\Domain\Service\PurchaseOrderLifecycle;

final class IssuePurchaseOrderHandler
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderLifecycle $lifecycle,
        private readonly PurchaseOrderSelfApprovalPolicy $selfApproval,
    ) {}

    public function __invoke(IssuePurchaseOrder $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->purchaseOrderId);

        $this->lifecycle->assertCanIssue((string) $order->status);

        if ((string) $order->approved_by_user_id === $command->actorId
            && ! $this->selfApproval->permits($order)) {
            throw SelfIssuanceNotPermitted::forPurchaseOrder($command->purchaseOrderId);
        }

        $order->issue($command->actorId);

        $this->orders->save($order);
    }
}
