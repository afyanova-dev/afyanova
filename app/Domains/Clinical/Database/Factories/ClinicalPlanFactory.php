<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClinicalPlan>
 */
class ClinicalPlanFactory extends Factory
{
    protected $model = ClinicalPlan::class;

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
            'authored_context' => ClinicalPlan::AUTHORED_CONTEXT,
            'plan_type' => 'GENERAL',
            'clinical_intent' => 'Manage acute bronchitis symptoms and evaluate in 5 days',
            'goals' => 'Resolution of fever and reduction of cough',
            'planned_actions' => 'Prescribe bronchodilator, advise rest and hydration',
            'instructions' => 'Avoid smoke exposure, drink warm liquids',
            'follow_up' => 'Return in 5 days if cough worsens',
            'status' => 'ACTIVE',
            'closed_reason' => null,
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

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function completed(): static
    {
        return $this->state(['status' => 'COMPLETED', 'closed_reason' => null]);
    }

    public function discontinued(string $reason = 'Patient opted for alternative care'): static
    {
        return $this->state(['status' => 'DISCONTINUED', 'closed_reason' => $reason]);
    }
}
