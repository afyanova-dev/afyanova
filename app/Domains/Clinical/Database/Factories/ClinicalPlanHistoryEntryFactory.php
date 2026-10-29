<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Domains\Clinical\Domain\Model\ClinicalPlanHistoryEntry;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClinicalPlanHistoryEntry>
 */
class ClinicalPlanHistoryEntryFactory extends Factory
{
    protected $model = ClinicalPlanHistoryEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'plan_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'change_type' => 'REVISION',
            'plan_type' => 'GENERAL',
            'clinical_intent' => 'Initial plan intent',
            'goals' => 'Initial goals',
            'planned_actions' => 'Initial actions',
            'instructions' => 'Initial instructions',
            'follow_up' => 'Initial follow-up',
            'status' => 'ACTIVE',
            'closed_reason' => null,
            'indication_diagnosis_id' => null,
            'indication_problem_id' => null,
            'reason' => null,
            'author_user_id' => (string) Str::uuid7(),
            'authored_context' => ClinicalPlan::AUTHORED_CONTEXT,
            'occurred_at' => now(),
            'recorded_at' => now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forPlan(string $planId): static
    {
        return $this->state(['plan_id' => $planId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }
}
