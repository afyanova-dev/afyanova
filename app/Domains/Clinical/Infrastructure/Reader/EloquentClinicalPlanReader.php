<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;
use App\Domains\Clinical\Domain\ClinicalPlanReader;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Shared\Domain\NotFound;

final class EloquentClinicalPlanReader implements ClinicalPlanReader
{
    public function summary(string $planId): ClinicalPlanSummary
    {
        $plan = ClinicalPlan::query()->whereKey($planId)->first()
            ?? throw NotFound::aggregate(ClinicalPlan::class, $planId);

        return $this->toSummary($plan);
    }

    public function forPatient(string $patientId, ?string $status, array $inScopeFacilityIds): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $query = ClinicalPlan::query()
            ->where('patient_id', $patientId)
            ->whereIn('facility_id', $inScopeFacilityIds);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $summaries = $query
            ->orderByDesc('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ClinicalPlan $plan): ClinicalPlanSummary => $this->toSummary($plan))
            ->all();

        return array_values($summaries);
    }

    private function toSummary(ClinicalPlan $plan): ClinicalPlanSummary
    {
        return new ClinicalPlanSummary(
            planId: (string) $plan->id,
            patientId: (string) $plan->patient_id,
            encounterId: (string) $plan->encounter_id,
            facilityId: (string) $plan->facility_id,
            authorUserId: (string) $plan->author_user_id,
            authoredContext: $plan->authored_context,
            planType: (string) $plan->plan_type,
            clinicalIntent: (string) $plan->clinical_intent,
            goals: $plan->goals,
            plannedActions: $plan->planned_actions,
            instructions: $plan->instructions,
            followUp: $plan->follow_up,
            status: (string) $plan->status,
            closedReason: $plan->closed_reason,
            indicationDiagnosisId: $plan->indication_diagnosis_id,
            indicationProblemId: $plan->indication_problem_id,
            occurredAt: (string) $plan->occurred_at->toISOString(),
            recordedAt: (string) $plan->recorded_at->toISOString(),
        );
    }
}
