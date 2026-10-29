<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\CaseTeamAssignment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CaseTeamAssignment>
 */
class CaseTeamAssignmentFactory extends Factory
{
    protected $model = CaseTeamAssignment::class;

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
            'staff_user_id' => (string) Str::uuid7(),
            'role' => 'SURGEON',
            'assigned_at' => now(),
            'replaced_at' => null,
            'version' => 0,
        ];
    }

    public function forCase(string $caseId): static
    {
        return $this->state(['case_id' => $caseId]);
    }

    public function withRole(string $role): static
    {
        return $this->state(['role' => $role]);
    }
}
