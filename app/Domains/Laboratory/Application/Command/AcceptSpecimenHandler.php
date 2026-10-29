<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabOrderRepository;
use App\Domains\Laboratory\Domain\LabSpecimenRepository;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;
use App\Domains\Laboratory\Domain\Service\LabSpecimenLifecycle;

final class AcceptSpecimenHandler
{
    public function __construct(
        private readonly LabSpecimenRepository $specimens,
        private readonly LabOrderRepository $orders,
        private readonly LabSpecimenLifecycle $lifecycle,
        private readonly LabOrderLifecycle $orderLifecycle,
    ) {}

    public function __invoke(AcceptSpecimen $command): void
    {
        $specimen = $this->specimens->getById($command->specimenId);

        $this->lifecycle->assertCanAccept((string) $specimen->status);

        $specimen->accept();

        $this->specimens->save($specimen);

        $order = $this->orders->getById((string) $specimen->order_id);

        if (in_array((string) $order->status, [LabOrder::STATUS_ACCEPTED, LabOrder::STATUS_SPECIMEN_PENDING], true)) {
            $this->orderLifecycle->assertCanMarkProcessing((string) $order->status);
            $order->markProcessing();
            $this->orders->save($order);
        }
    }
}
