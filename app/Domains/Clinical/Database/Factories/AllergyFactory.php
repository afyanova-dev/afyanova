<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\Allergy;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Allergy>
 */
class AllergyFactory extends Factory
{
    protected $model = Allergy::class;

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
            'authored_context' => Allergy::AUTHORED_CONTEXT_CLINICIAN,
            'category' => 'DRUG',
            'substance_name' => 'Penicillin',
            'substance_code' => '764146007',
            'substance_code_system' => 'SNOMED-CT',
            'reaction_description' => 'Urticaria and facial swelling',
            'severity' => 'MODERATE',
            'clinical_status' => 'ACTIVE',
            'verification_status' => 'SUSPECTED',
            'onset_date' => null,
            'resolved_at' => null,
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

    /** An un-coded allergen — name only (ADR-141 D-E). */
    public function uncoded(): static
    {
        return $this->state(['substance_code' => null, 'substance_code_system' => null]);
    }

    public function withCategory(string $category): static
    {
        return $this->state(['category' => $category]);
    }

    public function withClinicalStatus(string $status): static
    {
        return $this->state(['clinical_status' => $status]);
    }

    public function withVerificationStatus(string $status): static
    {
        return $this->state(['verification_status' => $status]);
    }

    public function confirmed(): static
    {
        return $this->state(['verification_status' => 'CONFIRMED']);
    }

    /** A RESOLVED allergy (was real, no longer active) with a resolution timestamp. */
    public function resolved(): static
    {
        return $this->state(['clinical_status' => 'RESOLVED', 'resolved_at' => now()]);
    }

    /** A REFUTED / INACTIVE allergy (the ADR-141 D-D biconditional). */
    public function refuted(): static
    {
        return $this->state(['clinical_status' => 'INACTIVE', 'verification_status' => 'REFUTED']);
    }
}
