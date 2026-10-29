<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;
use App\Domains\Laboratory\Domain\Service\LabResultReleaseGate;

final class ReleaseResultHandler
{
    public function __construct(
        private readonly LabResultRepository $results,
        private readonly LabOrderRepository $orders,
        private readonly LabResultReleaseGate $gate,
        private readonly LabOrderLifecycle $orderLifecycle,
    ) {}

    public function __invoke(ReleaseResult $command): void
    {
        $result = $this->results->getByIdForUpdate($command->resultId);

        $this->gate->assertReleasable($result);

        $result->release($command->actorId);

        $this->results->save($result);

        $order = $this->orders->getById((string) $result->order_id);
        if ((string) $order->status === LabOrder::STATUS_VERIFIED) {
            $this->orderLifecycle->assertCanMarkReleased((string) $order->status);
            $order->markReleased();
            $this->orders->save($order);
        }
    }
}
