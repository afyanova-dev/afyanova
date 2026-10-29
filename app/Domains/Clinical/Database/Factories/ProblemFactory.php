<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\Problem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Problem>
 */
class ProblemFactory extends Factory
{
    protected $model = Problem::class;

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
            'authored_context' => Problem::AUTHORED_CONTEXT,
            'code' => 'I10',
            'code_system' => 'ICD-10',
            'description' => 'Essential (primary) hypertension',
            'status' => 'ACTIVE',
            'onset_date' => null,
            'resolved_at' => null,
            'source_diagnosis_id' => null,
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

    /** An un-coded problem — description only (ADR-140 D-G). */
    public function uncoded(): static
    {
        return $this->state(['code' => null, 'code_system' => null]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    /** A RESOLVED problem with a resolution timestamp. */
    public function resolved(): static
    {
        return $this->state(['status' => 'RESOLVED', 'resolved_at' => now()]);
    }

    public function fromDiagnosis(string $diagnosisId): static
    {
        return $this->state(['source_diagnosis_id' => $diagnosisId]);
    }
}
