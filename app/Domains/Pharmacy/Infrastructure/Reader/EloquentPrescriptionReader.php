<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Reader;

use App\Domains\Pharmacy\Contracts\Dto\PrescriptionSummary;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PrescriptionReader;
use App\Shared\Domain\NotFound;
use Illuminate\Database\Eloquent\Builder;

final class EloquentPrescriptionReader implements PrescriptionReader
{
    public function summary(string $prescriptionId): PrescriptionSummary
    {
        $prescription = Prescription::query()->whereKey($prescriptionId)->first()
            ?? throw NotFound::aggregate(Prescription::class, $prescriptionId);

        return $this->toSummary($prescription);
    }

    public function forPatient(string $patientId, ?string $status): array
    {
        $query = Prescription::query()->where('patient_id', $patientId);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return $this->collect($query->orderByDesc('recorded_at')->orderBy('id'));
    }

    public function forFacility(string $facilityId, ?string $status): array
    {
        $query = Prescription::query()->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return $this->collect($query->orderByDesc('recorded_at')->orderBy('id'));
    }

    /**
     * @param  Builder<Prescription>  $query
     * @return list<PrescriptionSummary>
     */
    private function collect($query): array
    {
        return array_values(
            $query->get()
                ->map(fn (Prescription $p): PrescriptionSummary => $this->toSummary($p))
                ->all()
        );
    }

    private function toSummary(Prescription $p): PrescriptionSummary
    {
        return new PrescriptionSummary(
            prescriptionId: (string) $p->id,
            prescriptionNumber: (string) $p->prescription_number,
            facilityId: (string) $p->facility_id,
            patientId: (string) $p->patient_id,
            encounterId: $p->encounter_id !== null ? (string) $p->encounter_id : null,
            prescribingDecisionId: $p->prescribing_decision_id !== null ? (string) $p->prescribing_decision_id : null,
            authorUserId: (string) $p->author_user_id,
            changeType: $p->change_type,
            medicationCodeSystem: $p->medication_code_system,
            route: $p->route,
            urgency: $p->urgency,
            medicationName: $p->medication_name,
            medicationCode: $p->medication_code,
            dose: $p->dose,
            frequency: $p->frequency,
            duration: $p->duration,
            prescribedQuantity: $p->prescribed_quantity,
            dosingInstruction: $p->dosing_instruction,
            status: (string) $p->status,
            acceptedByUserId: $p->accepted_by_user_id !== null ? (string) $p->accepted_by_user_id : null,
            cancelledByUserId: $p->cancelled_by_user_id !== null ? (string) $p->cancelled_by_user_id : null,
            cancelReason: $p->cancel_reason,
            occurredAt: $p->occurred_at->toIso8601String(),
            recordedAt: $p->recorded_at->toIso8601String(),
            source: $p->source !== null ? (string) $p->source : null,
            prescriberName: $p->prescriber_name !== null ? (string) $p->prescriber_name : null,
            transcriptionNote: $p->transcription_note !== null ? (string) $p->transcription_note : null,
            inventoryItemId: $p->inventory_item_id !== null ? (string) $p->inventory_item_id : null,
            externalIntakeId: $p->external_intake_id !== null ? (string) $p->external_intake_id : null,
        );
    }
}
