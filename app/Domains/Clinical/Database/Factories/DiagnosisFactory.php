<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Diagnosis>
 */
class DiagnosisFactory extends Factory
{
    protected $model = Diagnosis::class;

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
            'authored_context' => Diagnosis::AUTHORED_CONTEXT,
            'code' => 'B54',
            'code_system' => 'ICD-10',
            'description' => 'Unspecified malaria',
            'clinical_status' => Diagnosis::CLINICAL_STATUS_DEFAULT,
            'supersedes_diagnosis_id' => null,
            'superseded_at' => null,
            'superseded_by_diagnosis_id' => null,
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

    public function authoredBy(string $userId): static
    {
        return $this->state(['author_user_id' => $userId]);
    }

    /** An un-coded diagnosis — description only (ADR-139 D2). */
    public function uncoded(): static
    {
        return $this->state(['code' => null, 'code_system' => null]);
    }

    public function withStatus(string $clinicalStatus): static
    {
        return $this->state(['clinical_status' => $clinicalStatus]);
    }
}
