<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\ProblemHistoryEntry;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProblemHistoryEntry>
 */
class ProblemHistoryEntryFactory extends Factory
{
    protected $model = ProblemHistoryEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'problem_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'change_type' => 'REVISION',
            'code' => 'I10',
            'code_system' => 'ICD-10',
            'description' => 'Essential (primary) hypertension',
            'status' => 'ACTIVE',
            'onset_date' => null,
            'resolved_at' => null,
            'reason' => null,
            'author_user_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'recorded_at' => now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forProblem(string $problemId): static
    {
        return $this->state(['problem_id' => $problemId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }
}
