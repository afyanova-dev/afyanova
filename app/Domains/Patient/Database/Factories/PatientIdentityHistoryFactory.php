<?php

declare(strict_types=1);

namespace App\Domains\Patient\Database\Factories;

use App\Domains\Patient\Domain\Enum\MergeHistoryStatus;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Model\PatientIdentityHistory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PatientIdentityHistory>
 */
class PatientIdentityHistoryFactory extends Factory
{
    protected $model = PatientIdentityHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'merge_id' => (string) Str::uuid7(),
            'surviving_patient_id' => (string) Str::uuid7(),
            'non_surviving_patient_id' => (string) Str::uuid7(),
            'non_surviving_patient_number' => 'PAT-'.fake()->unique()->numerify('########'),
            'non_surviving_prior_status' => PatientStatus::Active,
            'reason' => 'confirmed duplicate',
            'evidence' => 'matching NIDA + DOB + phone',
            'merged_by_user_id' => (string) Str::uuid7(),
            'merged_at' => now(),
            'status' => MergeHistoryStatus::Active,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function reversed(): static
    {
        return $this->state([
            'status' => MergeHistoryStatus::Reversed,
            'unmerged_by_user_id' => (string) Str::uuid7(),
            'unmerged_at' => now(),
        ]);
    }
}
