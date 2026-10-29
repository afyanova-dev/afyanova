<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;
use App\Domains\Laboratory\Domain\Service\LabResultLifecycle;

final class VerifyResultHandler
{
    public function __construct(
        private readonly LabResultRepository $results,
        private readonly LabOrderRepository $orders,
        private readonly LabResultLifecycle $lifecycle,
        private readonly LabOrderLifecycle $orderLifecycle,
    ) {}

    public function __invoke(VerifyResult $command): void
    {
        $result = $this->results->getByIdForUpdate($command->resultId);

        $this->lifecycle->assertCanVerify((string) $result->status);

        $result->verify($command->actorId);

        $this->results->save($result);

        $order = $this->orders->getById((string) $result->order_id);
        if ((string) $order->status === LabOrder::STATUS_RESULTED) {
            $this->orderLifecycle->assertCanMarkVerified((string) $order->status);
            $order->markVerified();
            $this->orders->save($order);
        }
    }
}
