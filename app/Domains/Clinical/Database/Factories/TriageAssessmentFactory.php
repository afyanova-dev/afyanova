<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\ValueObject\TriagePriority;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TriageAssessment>
 */
class TriageAssessmentFactory extends Factory
{
    protected $model = TriageAssessment::class;

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
            'authored_context' => TriageAssessment::AUTHORED_CONTEXT_NURSING,
            'chief_complaint' => 'Fever and cough for two days',
            'triage_priority' => TriagePriority::NORMAL,
            'acuity' => null,
            'consciousness' => null,
            'source_vitals_observation_id' => null,
            'supersedes_triage_assessment_id' => null,
            'superseded_at' => null,
            'superseded_by_triage_assessment_id' => null,
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

    public function withPriority(string $triagePriority): static
    {
        return $this->state(['triage_priority' => $triagePriority]);
    }

    /** A clinician-authored triage assessment (default is NURSING — ADR-145 D-M). */
    public function byClinician(): static
    {
        return $this->state(['authored_context' => TriageAssessment::AUTHORED_CONTEXT_CLINICIAN]);
    }
}
