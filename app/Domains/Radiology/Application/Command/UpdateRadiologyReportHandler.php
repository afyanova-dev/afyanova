<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Domain\Service\RadiologyReportLifecycle;

final class UpdateRadiologyReportHandler
{
    public function __construct(
        private readonly RadiologyReportRepository $reports,
        private readonly RadiologyReportLifecycle $lifecycle,
    ) {}

    public function __invoke(UpdateRadiologyReport $command): void
    {
        $report = $this->reports->getByIdForUpdate($command->reportId);

        $this->lifecycle->assertCanUpdate((string) $report->status);

        $report->updateContent($command->sections);

        $this->reports->save($report);
    }
}
