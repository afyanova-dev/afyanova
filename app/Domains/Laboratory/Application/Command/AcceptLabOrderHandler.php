<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;

final class AcceptLabOrderHandler
{
    public function __construct(
        private readonly LabOrderRepository $orders,
        private readonly LabOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(AcceptLabOrder $command): void
    {
        $order = $this->orders->getById($command->orderId);

        $this->lifecycle->assertCanAccept((string) $order->status);

        $order->accept();

        $this->orders->save($order);
    }
}
