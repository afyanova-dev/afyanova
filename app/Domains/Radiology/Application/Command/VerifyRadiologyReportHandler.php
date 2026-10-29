<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\Exception\RadiologyReportIncomplete;
use App\Domains\Radiology\Domain\ImagingOrderRepository;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;
use App\Domains\Radiology\Domain\Service\RadiologyReportLifecycle;

/**
 * Orchestrates `VerifyRadiologyReport` (ADR-167 D-D). Drives the report to
 * VERIFIED and, via the pre-reserved rollup, ratchets the order to VERIFIED.
 */
final class VerifyRadiologyReportHandler
{
    public function __construct(
        private readonly RadiologyReportRepository $reports,
        private readonly ImagingOrderRepository $orders,
        private readonly RadiologyReportLifecycle $lifecycle,
        private readonly ImagingOrderLifecycle $orderLifecycle,
    ) {}

    public function __invoke(VerifyRadiologyReport $command): void
    {
        $report = $this->reports->getByIdForUpdate($command->reportId);

        $this->lifecycle->assertCanVerify((string) $report->status);

        if (trim((string) $report->findings_text) === '' || trim((string) $report->impression_text) === '') {
            throw RadiologyReportIncomplete::forVerify($command->reportId);
        }

        $report->verify($command->actorId);

        $this->reports->save($report);

        $order = $this->orders->getByIdForUpdate((string) $report->order_id);

        if (in_array((string) $order->status, [ImagingOrder::STATUS_COMPLETED, ImagingOrder::STATUS_REPORTED], true)) {
            $this->orderLifecycle->assertCanMarkReportVerified((string) $order->status);
            $order->markReportVerified();
            $this->orders->save($order);
        }
    }
}
