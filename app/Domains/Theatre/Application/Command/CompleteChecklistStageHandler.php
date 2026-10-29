<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use Carbon\CarbonImmutable;

final class CompleteChecklistStageHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(CompleteChecklistStage $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanCompleteChecklistStage((string) $case->status);

        $case->completeChecklistStage([
            'stage' => strtoupper(trim($command->stage)),
            'completed_at' => CarbonImmutable::now()->toIso8601String(),
            'actor_user_id' => $command->actorId,
        ]);

        $this->cases->save($case);
    }
}
