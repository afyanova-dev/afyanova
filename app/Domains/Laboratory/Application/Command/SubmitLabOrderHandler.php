<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Exception\LabOrderHasNoItems;
use App\Domains\Laboratory\Domain\LabOrderReader;
use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;

final class SubmitLabOrderHandler
{
    public function __construct(
        private readonly LabOrderRepository $orders,
        private readonly LabOrderReader $reader,
        private readonly LabOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(SubmitLabOrder $command): void
    {
        $order = $this->orders->getById($command->orderId);

        $this->lifecycle->assertCanSubmit((string) $order->status);

        if ($this->reader->activeItemCount($command->orderId) === 0) {
            throw LabOrderHasNoItems::forSubmit($command->orderId);
        }

        $order->submit();

        $this->orders->save($order);
    }
}
