<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;
use App\Domains\Radiology\Domain\Service\ImagingStudyLifecycle;

/**
 * Orchestrates `StartImagingStudy` (ADR-166 D-F). Drives the study to
 * IN_PROGRESS and, via the pre-reserved rollup (ADR-165 D-F), ratchets the
 * order to IN_PROGRESS if it is still behind.
 */
final class StartImagingStudyHandler
{
    public function __construct(
        private readonly ImagingStudyRepository $studies,
        private readonly ImagingOrderRepository $orders,
        private readonly ImagingStudyLifecycle $lifecycle,
        private readonly ImagingOrderLifecycle $orderLifecycle,
    ) {}

    public function __invoke(StartImagingStudy $command): void
    {
        $study = $this->studies->getByIdForUpdate($command->studyId);

        $this->lifecycle->assertCanStart((string) $study->status);

        $study->start(
            $command->performedByUserId ?? $command->actorId,
            $command->verifiedByUserId ?? $command->actorId,
        );

        $this->studies->save($study);

        $order = $this->orders->getByIdForUpdate((string) $study->order_id);

        if (in_array((string) $order->status, [ImagingOrder::STATUS_ACCEPTED, ImagingOrder::STATUS_SCHEDULED], true)) {
            $this->orderLifecycle->assertCanMarkInProgress((string) $order->status);
            $order->markInProgress();
            $this->orders->save($order);
        }
    }
}
