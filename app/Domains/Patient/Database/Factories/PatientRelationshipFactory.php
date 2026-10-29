<?php

declare(strict_types=1);

namespace App\Domains\Patient\Database\Factories;

use App\Domains\Patient\Domain\Enum\RelationshipRole;
use App\Domains\Patient\Domain\Enum\RelationshipStatus;
use App\Domains\Patient\Domain\Model\PatientRelationship;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PatientRelationship>
 */
class PatientRelationshipFactory extends Factory
{
    protected $model = PatientRelationship::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => (string) Str::uuid7(),
            'role' => RelationshipRole::EmergencyContact,
            'related_name' => fake()->name(),
            'relationship_label' => 'sibling',
            'related_phone' => fake()->numerify('+2557########'),
            'note' => null,
            'status' => RelationshipStatus::Active,
            'recorded_by_user_id' => (string) Str::uuid7(),
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

    public function withRole(RelationshipRole $role): static
    {
        return $this->state(['role' => $role]);
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => RelationshipStatus::Inactive,
            'removed_by_user_id' => (string) Str::uuid7(),
            'removed_at' => now(),
        ]);
    }
}
