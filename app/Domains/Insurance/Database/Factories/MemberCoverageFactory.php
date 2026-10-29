<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Database\Factories;

use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Domains\Insurance\Domain\ValueObject\BeneficiaryRelationship;
use App\Domains\Insurance\Domain\ValueObject\CoveragePriority;
use App\Domains\Insurance\Domain\ValueObject\MemberCoverageStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MemberCoverage>
 */
class MemberCoverageFactory extends Factory
{
    protected $model = MemberCoverage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'patient_id' => (string) Str::uuid7(),
            'plan_id' => (string) Str::uuid7(),
            'payer_id' => (string) Str::uuid7(),
            'member_number' => 'M'.$this->faker->numerify('########'),
            'policy_number' => null,
            'card_number' => null,
            'relationship' => BeneficiaryRelationship::PRIMARY_MEMBER,
            'principal_member_coverage_id' => null,
            'priority' => CoveragePriority::PRIMARY,
            'effective_from' => $now->subYear()->toDateString(),
            'effective_to' => null,
            'status' => MemberCoverageStatus::ACTIVE,
            'terminated_at' => null,
            'terminated_by_user_id' => null,
            'termination_reason' => null,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
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

    public function priority(string $priority): static
    {
        return $this->state(['priority' => $priority]);
    }

    public function terminated(): static
    {
        return $this->state([
            'status' => MemberCoverageStatus::TERMINATED,
            'terminated_at' => CarbonImmutable::now(),
        ]);
    }
}
