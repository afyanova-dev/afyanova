<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Service\ImagingStudyLifecycle;

final class CancelImagingStudyHandler
{
    public function __construct(
        private readonly ImagingStudyRepository $studies,
        private readonly ImagingStudyLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelImagingStudy $command): void
    {
        $study = $this->studies->getById($command->studyId);

        $this->lifecycle->assertCanCancel((string) $study->status);

        $study->cancel(strtoupper(trim($command->reasonCode)), $command->detail);

        $this->studies->save($study);
    }
}
