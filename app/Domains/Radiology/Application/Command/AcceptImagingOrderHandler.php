<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;

final class AcceptImagingOrderHandler
{
    public function __construct(
        private readonly ImagingOrderRepository $orders,
        private readonly ImagingOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(AcceptImagingOrder $command): void
    {
        $order = $this->orders->getById($command->orderId);

        $this->lifecycle->assertCanAccept((string) $order->status);

        $order->accept();

        $this->orders->save($order);
    }
}
