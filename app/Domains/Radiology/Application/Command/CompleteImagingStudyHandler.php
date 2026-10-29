<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;
use App\Domains\Radiology\Domain\Service\ImagingStudyLifecycle;

/**
 * Orchestrates `CompleteImagingStudy` (ADR-166 D-F). Drives the study to
 * COMPLETED (emitting `ImagingStudyCompleted`) and, via the pre-reserved
 * rollup, ratchets the order to COMPLETED if it is still behind.
 */
final class CompleteImagingStudyHandler
{
    public function __construct(
        private readonly ImagingStudyRepository $studies,
        private readonly ImagingOrderRepository $orders,
        private readonly ImagingStudyLifecycle $lifecycle,
        private readonly ImagingOrderLifecycle $orderLifecycle,
    ) {}

    public function __invoke(CompleteImagingStudy $command): void
    {
        $study = $this->studies->getByIdForUpdate($command->studyId);

        $this->lifecycle->assertCanComplete((string) $study->status);

        $study->complete();

        $this->studies->save($study);

        $order = $this->orders->getByIdForUpdate((string) $study->order_id);

        if (in_array((string) $order->status, [ImagingOrder::STATUS_SCHEDULED, ImagingOrder::STATUS_IN_PROGRESS], true)) {
            $this->orderLifecycle->assertCanMarkCompleted((string) $order->status);
            $order->markCompleted();
            $this->orders->save($order);
        }
    }
}
