<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalPlanReader;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Exception\PrescribingDecisionContextMismatch;
use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Domains\Clinical\Domain\PrescribingDecisionRepository;
use App\Domains\Clinical\Domain\ProblemReader;
use Carbon\CarbonImmutable;

final class CorrectPrescribingDecisionHandler
{
    public function __construct(
        private readonly PrescribingDecisionRepository $decisions,
        private readonly ClinicalPlanReader $plans,
        private readonly DiagnosisReader $diagnoses,
        private readonly ProblemReader $problems,
    ) {}

    public function __invoke(CorrectPrescribingDecision $command): void
    {
        $original = $this->decisions->getByIdForUpdate($command->supersedesPrescribingDecisionId);

        if ($command->clinicalPlanId !== null) {
            $plan = $this->plans->summary($command->clinicalPlanId);
            if ($plan->patientId !== (string) $original->patient_id) {
                throw PrescribingDecisionContextMismatch::clinicalPlan(
                    (string) $original->patient_id,
                    $command->clinicalPlanId,
                    $plan->patientId,
                );
            }
        }

        if ($command->indicationDiagnosisId !== null) {
            $diagnosis = $this->diagnoses->summary($command->indicationDiagnosisId);
            if ($diagnosis->patientId !== (string) $original->patient_id) {
                throw PrescribingDecisionContextMismatch::indicationDiagnosis(
                    (string) $original->patient_id,
                    $command->indicationDiagnosisId,
                    $diagnosis->patientId,
                );
            }
        }

        if ($command->indicationProblemId !== null) {
            $problem = $this->problems->summary($command->indicationProblemId);
            if ($problem->patientId !== (string) $original->patient_id) {
                throw PrescribingDecisionContextMismatch::indicationProblem(
                    (string) $original->patient_id,
                    $command->indicationProblemId,
                    $problem->patientId,
                );
            }
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null
            ? CarbonImmutable::parse($command->occurredAt)
            : $original->occurred_at;

        $correction = PrescribingDecision::correcting(
            id: $command->newPrescribingDecisionId,
            original: $original,
            authorUserId: $command->actorId,
            medication: $command->medication,
            reason: $command->reason,
            dose: $command->dose,
            route: $command->route,
            frequency: $command->frequency,
            duration: $command->duration,
            quantity: $command->quantity,
            quantityValue: $command->quantityValue,
            quantityUnit: $command->quantityUnit,
            dosingInstruction: $command->dosingInstruction,
            therapeuticIntent: $command->therapeuticIntent,
            indication: $command->indication,
            urgency: $command->urgency,
            clinicalPlanId: $command->clinicalPlanId,
            indicationDiagnosisId: $command->indicationDiagnosisId,
            indicationProblemId: $command->indicationProblemId,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $original->markSupersededBy((string) $correction->id);

        $this->decisions->saveCorrection($original, $correction);
    }
}
