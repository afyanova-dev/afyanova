<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabQcRunRepository;
use App\Domains\Laboratory\Domain\LabResultReader;
use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\Service\LabQcRunLifecycle;

final class ReviewQcRunHandler
{
    public function __construct(
        private readonly LabQcRunRepository $runs,
        private readonly LabResultRepository $results,
        private readonly LabResultReader $resultReader,
        private readonly LabQcRunLifecycle $lifecycle,
    ) {}

    public function __invoke(ReviewQcRun $command): void
    {
        $run = $this->runs->getByIdForUpdate($command->qcRunId);

        $this->lifecycle->assertCanReview($run);

        $run->review($command->actorId, $command->note);

        $this->runs->save($run);

        $held = $this->resultReader->heldIdsForTest(
            (string) $run->facility_id,
            $run->test_definition_id,
            RecordQcRunHandler::QC_HOLD_REASON,
        );

        foreach ($held as $resultId) {
            $result = $this->results->getByIdForUpdate($resultId);
            $result->clearReleaseHold();
            $this->results->save($result);
        }
    }
}
