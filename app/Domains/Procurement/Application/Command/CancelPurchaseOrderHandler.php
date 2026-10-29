<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Domains\Procurement\Domain\Service\PurchaseOrderLifecycle;

final class CancelPurchaseOrderHandler
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelPurchaseOrder $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->purchaseOrderId);

        $this->lifecycle->assertCanCancel((string) $order->status);

        $order->cancel($command->actorId, $command->reason);

        $this->orders->save($order);
    }
}
