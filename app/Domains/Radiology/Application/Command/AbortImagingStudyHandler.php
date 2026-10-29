<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Service\ImagingStudyLifecycle;

final class AbortImagingStudyHandler
{
    public function __construct(
        private readonly ImagingStudyRepository $studies,
        private readonly ImagingStudyLifecycle $lifecycle,
    ) {}

    public function __invoke(AbortImagingStudy $command): void
    {
        $study = $this->studies->getById($command->studyId);

        $this->lifecycle->assertCanAbort((string) $study->status);

        $study->abort(strtoupper(trim($command->reasonCode)), $command->detail);

        $this->studies->save($study);
    }
}
