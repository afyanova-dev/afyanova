<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;

final class CancelImagingOrderHandler
{
    public function __construct(
        private readonly ImagingOrderRepository $orders,
        private readonly ImagingOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelImagingOrder $command): void
    {
        $order = $this->orders->getById($command->orderId);

        $this->lifecycle->assertCanCancel((string) $order->status);

        $order->cancel($command->reason);

        $this->orders->save($order);
    }
}
