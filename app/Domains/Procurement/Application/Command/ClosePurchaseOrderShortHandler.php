<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Model\PoLine;
use App\Domains\Procurement\Domain\PurchaseOrderRepository;
use App\Domains\Procurement\Domain\Service\PurchaseOrderLifecycle;

final class ClosePurchaseOrderShortHandler
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(ClosePurchaseOrderShort $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->purchaseOrderId);

        $this->lifecycle->assertCanCloseShort((string) $order->status);

        $order->closeShort($command->actorId, $command->reason);

        // A close-short is a line-tail outcome too: lines that still owe a
        // balance become CLOSED_SHORT so no receiving query can pick them up.
        // Fully satisfied lines keep their OPEN history.
        PoLine::query()
            ->where('po_id', $order->id)
            ->where('status', PoLine::STATUS_OPEN)
            ->whereRaw('received_qty < ordered_base_qty')
            ->update(['status' => PoLine::STATUS_CLOSED_SHORT]);

        $this->orders->save($order);
    }
}
