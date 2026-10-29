<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalPlanRepository;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Exception\ClinicalPlanContextMismatch;
use App\Domains\Clinical\Domain\ProblemReader;
use Carbon\CarbonImmutable;

final class UpdateClinicalPlanHandler
{
    public function __construct(
        private readonly ClinicalPlanRepository $plans,
        private readonly DiagnosisReader $diagnoses,
        private readonly ProblemReader $problems,
    ) {}

    public function __invoke(UpdateClinicalPlan $command): void
    {
        $plan = $this->plans->getByIdForUpdate($command->clinicalPlanId);

        if ($command->indicationDiagnosisId !== null) {
            $diagnosis = $this->diagnoses->summary($command->indicationDiagnosisId);
            if ($diagnosis->patientId !== (string) $plan->patient_id) {
                throw ClinicalPlanContextMismatch::indicationDiagnosis(
                    (string) $plan->patient_id,
                    $command->indicationDiagnosisId,
                    $diagnosis->patientId,
                );
            }
        }

        if ($command->indicationProblemId !== null) {
            $problem = $this->problems->summary($command->indicationProblemId);
            if ($problem->patientId !== (string) $plan->patient_id) {
                throw ClinicalPlanContextMismatch::indicationProblem(
                    (string) $plan->patient_id,
                    $command->indicationProblemId,
                    $problem->patientId,
                );
            }
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null
            ? CarbonImmutable::parse($command->occurredAt)
            : $now;

        $plan->revise(
            changeType: $command->changeType,
            planType: $command->planType,
            clinicalIntent: $command->clinicalIntent,
            goals: $command->goals,
            plannedActions: $command->plannedActions,
            instructions: $command->instructions,
            followUp: $command->followUp,
            indicationDiagnosisId: $command->indicationDiagnosisId,
            indicationProblemId: $command->indicationProblemId,
            reason: $command->reason,
            authorUserId: $command->actorId,
            changeOccurredAt: $occurredAt,
            changeRecordedAt: $now,
        );

        $this->plans->save($plan);
    }
}
