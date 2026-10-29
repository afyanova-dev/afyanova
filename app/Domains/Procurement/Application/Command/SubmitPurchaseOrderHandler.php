<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Domains\Procurement\Domain\Service\PurchaseOrderLifecycle;

final class SubmitPurchaseOrderHandler
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(SubmitPurchaseOrder $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->purchaseOrderId);

        $this->lifecycle->assertCanSubmit((string) $order->status);

        $order->submit();

        $this->orders->save($order);
    }
}
