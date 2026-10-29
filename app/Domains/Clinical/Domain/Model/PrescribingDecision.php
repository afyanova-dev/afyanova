<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\PrescriptionIssued;
use App\Domains\Clinical\Database\Factories\PrescribingDecisionFactory;
use App\Domains\Clinical\Domain\Exception\IllegalPrescribingDecisionStatusTransition;
use App\Domains\Clinical\Domain\Exception\PrescribingDecisionAlreadySuperseded;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Domains\Clinical\Domain\ValueObject\PrescribingDecisionStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Encounter-originated therapeutic prescribing decision for a patient
 * (12-clinical-domain.md §34–§39, §76; 16-pharmacy-domain.md §6, §19, §21;
 * ADR-131, ADR-132, ADR-135, ADR-136, ADR-143).
 *
 * Clinical owns the therapeutic prescribing DECISION; Pharmacy owns the
 * authoritative Prescription record, dispensing and lifecycle (PHASE 9).
 *
 * LIFECYCLE (ADR-143 D-J): IMMUTABLE RECORDED + superseding-record correction
 * (the C.3 Diagnosis model) + a clinical-stop status (DISCONTINUED).
 *
 * @property string $status PrescribingDecisionStatus value (ACTIVE | SUPERSEDED | DISCONTINUED)
 * @property string|null $quantity_value the prescriber-authored structured total quantity (ADR-183 D-B), decimal:4 numeric string
 * @property string|null $quantity_unit open-string unit for $quantity_value (ADR-183 D-B)
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 */
final class PrescribingDecision extends AggregateRoot
{
    /** @use HasFactory<PrescribingDecisionFactory> */
    use HasFactory;

    public const AUTHORED_CONTEXT = 'CLINICIAN';

    protected $table = 'clin_prescribing_decisions';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'medication_name', 'medication_code', 'medication_code_system',
        'dose', 'route', 'frequency', 'duration', 'quantity',
        'quantity_value', 'quantity_unit',
        'dosing_instruction', 'therapeutic_intent', 'indication', 'urgency',
        'status', 'discontinued_reason', 'correction_reason',
        'supersedes_prescribing_decision_id', 'superseded_at',
        'superseded_by_prescribing_decision_id',
        'clinical_plan_id', 'indication_diagnosis_id', 'indication_problem_id',
        'occurred_at', 'recorded_at',
    ];

    protected static function newFactory(): PrescribingDecisionFactory
    {
        return PrescribingDecisionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_value' => 'decimal:4',
            'superseded_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $authorUserId,
        MedicationSubstance $medication,
        ?string $dose,
        ?string $route,
        ?string $frequency,
        ?string $duration,
        ?string $quantity,
        ?string $quantityValue,
        ?string $quantityUnit,
        ?string $dosingInstruction,
        ?string $therapeuticIntent,
        ?string $indication,
        ?string $urgency,
        ?string $clinicalPlanId,
        ?string $indicationDiagnosisId,
        ?string $indicationProblemId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $decision = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'author_user_id' => $authorUserId,
            'authored_context' => self::AUTHORED_CONTEXT,
            'medication_name' => $medication->name,
            'medication_code' => $medication->code,
            'medication_code_system' => $medication->codeSystem,
            'dose' => $dose !== null && trim($dose) !== '' ? trim($dose) : null,
            'route' => $route !== null && trim($route) !== '' ? trim($route) : null,
            'frequency' => $frequency !== null && trim($frequency) !== '' ? trim($frequency) : null,
            'duration' => $duration !== null && trim($duration) !== '' ? trim($duration) : null,
            'quantity' => $quantity !== null && trim($quantity) !== '' ? trim($quantity) : null,
            'quantity_value' => $quantityValue,
            'quantity_unit' => $quantityUnit !== null && trim($quantityUnit) !== '' ? trim($quantityUnit) : null,
            'dosing_instruction' => $dosingInstruction !== null && trim($dosingInstruction) !== '' ? trim($dosingInstruction) : null,
            'therapeutic_intent' => $therapeuticIntent !== null && trim($therapeuticIntent) !== '' ? trim($therapeuticIntent) : null,
            'indication' => $indication !== null && trim($indication) !== '' ? trim($indication) : null,
            'urgency' => $urgency !== null && trim($urgency) !== '' ? trim($urgency) : null,
            'status' => PrescribingDecisionStatus::ACTIVE,
            'discontinued_reason' => null,
            'correction_reason' => null,
            'supersedes_prescribing_decision_id' => null,
            'superseded_at' => null,
            'superseded_by_prescribing_decision_id' => null,
            'clinical_plan_id' => $clinicalPlanId,
            'indication_diagnosis_id' => $indicationDiagnosisId,
            'indication_problem_id' => $indicationProblemId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $decision->recordThat(new PrescriptionIssued(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            prescribingDecisionId: $id,
            authorUserId: $authorUserId,
            medicationCodeSystem: $medication->codeSystem,
            route: $route,
            urgency: $urgency,
            changeType: null,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $decision;
    }

    public static function correcting(
        string $id,
        self $original,
        string $authorUserId,
        MedicationSubstance $medication,
        string $reason,
        ?string $dose,
        ?string $route,
        ?string $frequency,
        ?string $duration,
        ?string $quantity,
        ?string $quantityValue,
        ?string $quantityUnit,
        ?string $dosingInstruction,
        ?string $therapeuticIntent,
        ?string $indication,
        ?string $urgency,
        ?string $clinicalPlanId,
        ?string $indicationDiagnosisId,
        ?string $indicationProblemId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException(
                'CorrectPrescribingDecision: reason is required (ADR-143 D-J).'
            );
        }

        if ($original->status === PrescribingDecisionStatus::SUPERSEDED) {
            throw PrescribingDecisionAlreadySuperseded::for(
                (string) $original->id,
                (string) $original->superseded_by_prescribing_decision_id,
            );
        }

        if ($original->status !== PrescribingDecisionStatus::ACTIVE) {
            throw IllegalPrescribingDecisionStatusTransition::correct((string) $original->status);
        }

        $decision = new self([
            'id' => $id,
            'tenant_id' => (string) $original->tenant_id,
            'facility_id' => (string) $original->facility_id,
            'patient_id' => (string) $original->patient_id,
            'encounter_id' => (string) $original->encounter_id,
            'author_user_id' => $authorUserId,
            'authored_context' => self::AUTHORED_CONTEXT,
            'medication_name' => $medication->name,
            'medication_code' => $medication->code,
            'medication_code_system' => $medication->codeSystem,
            'dose' => $dose !== null && trim($dose) !== '' ? trim($dose) : null,
            'route' => $route !== null && trim($route) !== '' ? trim($route) : null,
            'frequency' => $frequency !== null && trim($frequency) !== '' ? trim($frequency) : null,
            'duration' => $duration !== null && trim($duration) !== '' ? trim($duration) : null,
            'quantity' => $quantity !== null && trim($quantity) !== '' ? trim($quantity) : null,
            'quantity_value' => $quantityValue,
            'quantity_unit' => $quantityUnit !== null && trim($quantityUnit) !== '' ? trim($quantityUnit) : null,
            'dosing_instruction' => $dosingInstruction !== null && trim($dosingInstruction) !== '' ? trim($dosingInstruction) : null,
            'therapeutic_intent' => $therapeuticIntent !== null && trim($therapeuticIntent) !== '' ? trim($therapeuticIntent) : null,
            'indication' => $indication !== null && trim($indication) !== '' ? trim($indication) : null,
            'urgency' => $urgency !== null && trim($urgency) !== '' ? trim($urgency) : null,
            'status' => PrescribingDecisionStatus::ACTIVE,
            'discontinued_reason' => null,
            'correction_reason' => $reason,
            'supersedes_prescribing_decision_id' => (string) $original->id,
            'superseded_at' => null,
            'superseded_by_prescribing_decision_id' => null,
            'clinical_plan_id' => $clinicalPlanId ?? $original->clinical_plan_id,
            'indication_diagnosis_id' => $indicationDiagnosisId,
            'indication_problem_id' => $indicationProblemId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $decision->recordThat(new PrescriptionIssued(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $original->tenant_id,
            facilityId: (string) $original->facility_id,
            patientId: (string) $original->patient_id,
            encounterId: (string) $original->encounter_id,
            prescribingDecisionId: $id,
            authorUserId: $authorUserId,
            medicationCodeSystem: $medication->codeSystem,
            route: $route,
            urgency: $urgency,
            changeType: 'CORRECTION',
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $decision;
    }

    public function markSupersededBy(string $supersedingId): void
    {
        if ($this->status === PrescribingDecisionStatus::SUPERSEDED) {
            throw PrescribingDecisionAlreadySuperseded::for(
                (string) $this->id,
                (string) $this->superseded_by_prescribing_decision_id,
            );
        }

        if ($this->status !== PrescribingDecisionStatus::ACTIVE) {
            throw IllegalPrescribingDecisionStatusTransition::correct((string) $this->status);
        }

        $this->status = PrescribingDecisionStatus::SUPERSEDED;
        $this->superseded_at = CarbonImmutable::now();
        $this->superseded_by_prescribing_decision_id = $supersedingId;
    }

    public function discontinue(string $reason): void
    {
        if ($this->status !== PrescribingDecisionStatus::ACTIVE) {
            throw IllegalPrescribingDecisionStatusTransition::discontinue((string) $this->status);
        }

        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'DiscontinuePrescribingDecision: reason is required (ADR-143 D-J).'
            );
        }

        $this->status = PrescribingDecisionStatus::DISCONTINUED;
        $this->discontinued_reason = $trimmed;
    }

    public function isActive(): bool
    {
        return $this->status === PrescribingDecisionStatus::ACTIVE;
    }

    public function isSuperseded(): bool
    {
        return $this->status === PrescribingDecisionStatus::SUPERSEDED;
    }

    public function isDiscontinued(): bool
    {
        return $this->status === PrescribingDecisionStatus::DISCONTINUED;
    }

    public function medication(): MedicationSubstance
    {
        return MedicationSubstance::of(
            (string) $this->medication_name,
            $this->medication_code,
            $this->medication_code_system,
        );
    }
}
