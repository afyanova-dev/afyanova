<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Domains\Clinical\Domain\PrescribingDecisionReader;
use App\Shared\Domain\NotFound;

final class EloquentPrescribingDecisionReader implements PrescribingDecisionReader
{
    public function summary(string $decisionId): PrescribingDecisionSummary
    {
        $decision = PrescribingDecision::query()->whereKey($decisionId)->first()
            ?? throw NotFound::aggregate(PrescribingDecision::class, $decisionId);

        return $this->toSummary($decision);
    }

    public function forPatient(string $patientId, ?string $status, array $inScopeFacilityIds): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $query = PrescribingDecision::query()
            ->where('patient_id', $patientId)
            ->whereIn('facility_id', $inScopeFacilityIds);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $summaries = $query
            ->orderByDesc('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PrescribingDecision $decision): PrescribingDecisionSummary => $this->toSummary($decision))
            ->all();

        return array_values($summaries);
    }

    private function toSummary(PrescribingDecision $decision): PrescribingDecisionSummary
    {
        return new PrescribingDecisionSummary(
            prescribingDecisionId: (string) $decision->id,
            patientId: (string) $decision->patient_id,
            encounterId: (string) $decision->encounter_id,
            facilityId: (string) $decision->facility_id,
            authorUserId: (string) $decision->author_user_id,
            authoredContext: $decision->authored_context,
            medicationName: (string) $decision->medication_name,
            medicationCode: $decision->medication_code,
            medicationCodeSystem: $decision->medication_code_system,
            dose: $decision->dose,
            route: $decision->route,
            frequency: $decision->frequency,
            duration: $decision->duration,
            quantity: $decision->quantity,
            dosingInstruction: $decision->dosing_instruction,
            therapeuticIntent: $decision->therapeutic_intent,
            indication: $decision->indication,
            urgency: $decision->urgency,
            status: (string) $decision->status,
            discontinuedReason: $decision->discontinued_reason,
            correctionReason: $decision->correction_reason,
            supersedesPrescribingDecisionId: $decision->supersedes_prescribing_decision_id,
            supersededAt: $decision->superseded_at?->toISOString(),
            supersededByPrescribingDecisionId: $decision->superseded_by_prescribing_decision_id,
            clinicalPlanId: $decision->clinical_plan_id,
            indicationDiagnosisId: $decision->indication_diagnosis_id,
            indicationProblemId: $decision->indication_problem_id,
            occurredAt: (string) $decision->occurred_at->toISOString(),
            recordedAt: (string) $decision->recorded_at->toISOString(),
            quantityValue: $decision->quantity_value,
            quantityUnit: $decision->quantity_unit,
        );
    }
}
