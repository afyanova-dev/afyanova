<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PrescribingDecision>
 */
class PrescribingDecisionFactory extends Factory
{
    protected $model = PrescribingDecision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'author_user_id' => (string) Str::uuid7(),
            'authored_context' => PrescribingDecision::AUTHORED_CONTEXT,
            'medication_name' => 'Amoxicillin',
            'medication_code' => '500123',
            'medication_code_system' => 'RxNorm',
            'dose' => '500 mg',
            'route' => 'ORAL',
            'frequency' => 'TDS',
            'duration' => '7 days',
            'quantity' => '21 capsules',   // ADR-183 D-N: default state = LEGACY FREE-TEXT (this IS the null-structured-quantity coverage)
            'quantity_value' => null,
            'quantity_unit' => null,
            'dosing_instruction' => 'Take with food',
            'therapeutic_intent' => 'Eradication of bacterial infection',
            'indication' => 'Acute bacterial sinusitis',
            'urgency' => 'ROUTINE',
            'status' => 'ACTIVE',
            'discontinued_reason' => null,
            'correction_reason' => null,
            'supersedes_prescribing_decision_id' => null,
            'superseded_at' => null,
            'superseded_by_prescribing_decision_id' => null,
            'clinical_plan_id' => null,
            'indication_diagnosis_id' => null,
            'indication_problem_id' => null,
            'occurred_at' => now(),
            'recorded_at' => now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function forEncounter(string $encounterId): static
    {
        return $this->state(['encounter_id' => $encounterId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function authoredBy(string $userId): static
    {
        return $this->state(['author_user_id' => $userId]);
    }

    public function uncoded(): static
    {
        return $this->state(['medication_code' => null, 'medication_code_system' => null]);
    }

    /**
     * ADR-183 D-E / D-N: the prescriber recorded a structured authorised
     * quantity — the free-text `quantity` is NULLed (never both).
     */
    public function withAuthorizedQuantity(string $value, string $unit): static
    {
        return $this->state([
            'quantity' => null,
            'quantity_value' => $value,
            'quantity_unit' => $unit,
        ]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function discontinued(string $reason = 'Adverse reaction observed'): static
    {
        return $this->state(['status' => 'DISCONTINUED', 'discontinued_reason' => $reason]);
    }
}
