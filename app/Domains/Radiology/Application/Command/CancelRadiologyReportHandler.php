<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Domain\Service\RadiologyReportLifecycle;

final class CancelRadiologyReportHandler
{
    public function __construct(
        private readonly RadiologyReportRepository $reports,
        private readonly RadiologyReportLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelRadiologyReport $command): void
    {
        $report = $this->reports->getByIdForUpdate($command->reportId);

        $this->lifecycle->assertCanCancel((string) $report->status);

        $report->cancelReport($command->reason);

        $this->reports->save($report);
    }
}
