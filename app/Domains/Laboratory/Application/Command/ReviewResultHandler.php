<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\Service\LabResultLifecycle;

final class ReviewResultHandler
{
    public function __construct(
        private readonly LabResultRepository $results,
        private readonly LabResultLifecycle $lifecycle,
    ) {}

    public function __invoke(ReviewResult $command): void
    {
        $result = $this->results->getById($command->resultId);

        $this->lifecycle->assertCanReview((string) $result->status);

        $result->review($command->actorId);

        $this->results->save($result);
    }
}
