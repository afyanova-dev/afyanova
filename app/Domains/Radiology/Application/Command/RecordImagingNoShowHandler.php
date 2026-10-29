<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\ImagingStudyRepository;
use App\Domains\Radiology\Domain\Service\ImagingStudyLifecycle;

final class RecordImagingNoShowHandler
{
    public function __construct(
        private readonly ImagingStudyRepository $studies,
        private readonly ImagingStudyLifecycle $lifecycle,
    ) {}

    public function __invoke(RecordImagingNoShow $command): void
    {
        $study = $this->studies->getById($command->studyId);

        $this->lifecycle->assertCanRecordNoShow((string) $study->status);

        $study->recordNoShow();

        $this->studies->save($study);
    }
}
