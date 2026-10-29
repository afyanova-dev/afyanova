<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SurgicalProcedure>
 */
class SurgicalProcedureFactory extends Factory
{
    protected $model = SurgicalProcedure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'case_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'sequence' => 1,
            'planned_description' => 'Appendectomy',
            'actual_description' => null,
            'started_at' => null,
            'ended_at' => null,
            'primary_surgeon_user_id' => null,
            'outcome' => null,
            'findings' => null,
            'complications' => null,
            'status' => SurgicalProcedure::STATUS_PLANNED,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function forCase(string $caseId): static
    {
        return $this->state(['case_id' => $caseId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function withSequence(int $sequence): static
    {
        return $this->state(['sequence' => $sequence]);
    }
}
