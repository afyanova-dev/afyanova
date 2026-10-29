<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;

final class RejectLabOrderHandler
{
    public function __construct(
        private readonly LabOrderRepository $orders,
        private readonly LabOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(RejectLabOrder $command): void
    {
        $order = $this->orders->getById($command->orderId);

        $this->lifecycle->assertCanReject((string) $order->status);

        $order->reject($command->reason);

        $this->orders->save($order);
    }
}
