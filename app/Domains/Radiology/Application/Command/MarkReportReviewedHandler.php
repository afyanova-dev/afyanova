<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Domain\Service\RadiologyReportLifecycle;

final class MarkReportReviewedHandler
{
    public function __construct(
        private readonly RadiologyReportRepository $reports,
        private readonly RadiologyReportLifecycle $lifecycle,
    ) {}

    public function __invoke(MarkReportReviewed $command): void
    {
        $report = $this->reports->getById($command->reportId);

        $this->lifecycle->assertCanMarkReviewed((string) $report->status);

        $report->markReviewed($command->actorId);

        $this->reports->save($report);
    }
}
