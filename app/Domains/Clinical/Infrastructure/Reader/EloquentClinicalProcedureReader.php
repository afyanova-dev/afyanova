<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\ClinicalProcedureReader;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Domains\Clinical\Domain\Model\ProcedureAmendment;
use App\Shared\Domain\NotFound;

final class EloquentClinicalProcedureReader implements ClinicalProcedureReader
{
    public function summary(string $procedureId): ProcedureSummary
    {
        $procedure = ClinicalProcedure::query()->whereKey($procedureId)->first()
            ?? throw NotFound::aggregate(ClinicalProcedure::class, $procedureId);

        return $this->toSummary($procedure);
    }

    public function forEncounter(string $encounterId, array $inScopeFacilityIds): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $procedures = ClinicalProcedure::query()
            ->where('encounter_id', $encounterId)
            ->whereIn('facility_id', $inScopeFacilityIds)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return array_values($procedures
            ->map(fn (ClinicalProcedure $procedure): ProcedureSummary => $this->toSummary($procedure))
            ->all());
    }

    public function forPatient(string $patientId, array $inScopeFacilityIds): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $procedures = ClinicalProcedure::query()
            ->where('patient_id', $patientId)
            ->whereIn('facility_id', $inScopeFacilityIds)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return array_values($procedures
            ->map(fn (ClinicalProcedure $procedure): ProcedureSummary => $this->toSummary($procedure))
            ->all());
    }

    private function toSummary(ClinicalProcedure $procedure): ProcedureSummary
    {
        $amendments = array_values(ProcedureAmendment::query()
            ->where('procedure_id', (string) $procedure->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ProcedureAmendment $amendment): array => [
                'amendmentId' => (string) $amendment->id,
                'amendmentType' => (string) $amendment->amendment_type,
                'body' => (string) $amendment->body,
                'reason' => (string) $amendment->reason,
                'changedFields' => array_values($amendment->changed_fields ?? []),
                'authorUserId' => (string) $amendment->author_user_id,
                'occurredAt' => (string) $amendment->occurred_at->toISOString(),
                'recordedAt' => (string) $amendment->recorded_at->toISOString(),
            ])
            ->all());

        return new ProcedureSummary(
            procedureId: (string) $procedure->id,
            patientId: (string) $procedure->patient_id,
            encounterId: (string) $procedure->encounter_id,
            facilityId: (string) $procedure->facility_id,
            authorUserId: (string) $procedure->author_user_id,
            authoredContext: $procedure->authored_context,
            procedureNumber: (string) $procedure->procedure_number,
            status: $procedure->status->value,
            definitionId: (string) $procedure->definition_id,
            definitionVersion: (int) $procedure->definition_version,
            procedureCategory: $procedure->procedure_category,
            requestedByUserId: (string) $procedure->requested_by_user_id,
            authorizedByUserId: $procedure->authorized_by_user_id,
            authorizationBasis: $procedure->authorization_basis,
            authorizationProtocolReference: $procedure->authorization_protocol_reference,
            authorizationProtocolApprovalId: $procedure->authorization_protocol_approval_id,
            authorizationProtocolVersion: $procedure->authorization_protocol_version,
            performedByUserId: $procedure->performed_by_user_id,
            locationLabel: $procedure->location_label,
            site: $procedure->site,
            laterality: $procedure->laterality,
            outcome: $procedure->outcome,
            outcomeDetail: $procedure->outcome_detail,
            complications: $procedure->complications,
            statusReason: $procedure->status_reason,
            requestedAt: $procedure->requested_at?->toISOString(),
            authorizedAt: $procedure->authorized_at?->toISOString(),
            readyAt: $procedure->ready_at?->toISOString(),
            startedAt: $procedure->started_at?->toISOString(),
            completedAt: $procedure->completed_at?->toISOString(),
            sourceDiagnosisId: $procedure->source_diagnosis_id,
            sourceClinicalPlanId: $procedure->source_clinical_plan_id,
            indication: $procedure->indication,
            clinicalNoteId: $procedure->clinical_note_id,
            financialClearanceRef: $procedure->financial_clearance_ref,
            financialPolicyVersion: $procedure->financial_policy_version,
            occurredAt: (string) $procedure->occurred_at->toISOString(),
            recordedAt: (string) $procedure->recorded_at->toISOString(),
            amendments: $amendments,
        );
    }
}
