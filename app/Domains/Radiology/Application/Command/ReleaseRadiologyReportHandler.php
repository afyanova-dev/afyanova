<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;
use App\Domains\Radiology\Domain\Service\RadiologyReportReleaseGate;

/**
 * Orchestrates `ReleaseRadiologyReport` (ADR-167 D-D). Passes the release gate,
 * drives the report to RELEASED (emitting `RadiologyReportReleased`) and
 * ratchets the order to RELEASED via the pre-reserved rollup.
 *
 * `getByIdForUpdate` — the T.2 concurrency lesson (a duplicate release would
 * re-assign `status` to RELEASED; the lifecycle assert rejects it, but the lock
 * serializes concurrent attempts).
 */
final class ReleaseRadiologyReportHandler
{
    public function __construct(
        private readonly RadiologyReportRepository $reports,
        private readonly ImagingOrderRepository $orders,
        private readonly RadiologyReportReleaseGate $gate,
        private readonly ImagingOrderLifecycle $orderLifecycle,
    ) {}

    public function __invoke(ReleaseRadiologyReport $command): void
    {
        $report = $this->reports->getByIdForUpdate($command->reportId);

        $this->gate->assertReleasable($report);

        $report->release($command->actorId);

        $this->reports->save($report);

        $order = $this->orders->getByIdForUpdate((string) $report->order_id);

        if (in_array((string) $order->status, [ImagingOrder::STATUS_REPORTED, ImagingOrder::STATUS_VERIFIED], true)) {
            $this->orderLifecycle->assertCanMarkReleased((string) $order->status);
            $order->markReleased();
            $this->orders->save($order);
        }
    }
}
