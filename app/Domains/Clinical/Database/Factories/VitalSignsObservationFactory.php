<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VitalSignsObservation>
 */
class VitalSignsObservationFactory extends Factory
{
    protected $model = VitalSignsObservation::class;

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
            'authored_context' => VitalSignsObservation::AUTHORED_CONTEXT_NURSING,
            'temperature' => 36.8,
            'heart_rate' => 78,
            'respiratory_rate' => 16,
            'systolic_bp' => 122,
            'diastolic_bp' => 78,
            'oxygen_saturation' => 98,
            'weight' => 72.5,
            'height' => 174.0,
            'pain_score' => 2,
            'supersedes_observation_id' => null,
            'superseded_at' => null,
            'superseded_by_observation_id' => null,
            'reason' => null,
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

    public function recordedBy(string $userId): static
    {
        return $this->state(['author_user_id' => $userId]);
    }

    /** A single measurement only — proves the "at least one" rule is satisfiable minimally. */
    public function temperatureOnly(float $celsius = 37.0): static
    {
        return $this->state([
            'temperature' => $celsius,
            'heart_rate' => null, 'respiratory_rate' => null,
            'systolic_bp' => null, 'diastolic_bp' => null, 'oxygen_saturation' => null,
            'weight' => null, 'height' => null, 'pain_score' => null,
        ]);
    }
}
