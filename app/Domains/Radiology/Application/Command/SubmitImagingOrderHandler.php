<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\Exception\ImagingOrderHasNoItems;
use App\Domains\Radiology\Domain\ImagingOrderReader;
use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;

final class SubmitImagingOrderHandler
{
    public function __construct(
        private readonly ImagingOrderRepository $orders,
        private readonly ImagingOrderReader $reader,
        private readonly ImagingOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(SubmitImagingOrder $command): void
    {
        $order = $this->orders->getById($command->orderId);

        $this->lifecycle->assertCanSubmit((string) $order->status);

        if ($this->reader->activeItemCount($command->orderId) === 0) {
            throw ImagingOrderHasNoItems::forSubmit($command->orderId);
        }

        $order->submit();

        $this->orders->save($order);
    }
}
