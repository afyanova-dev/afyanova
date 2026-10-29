<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CareTeamMembership>
 */
class CareTeamMembershipFactory extends Factory
{
    protected $model = CareTeamMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'staff_user_id' => (string) Str::uuid7(),
            'team_role' => TeamRole::ATTENDING,
            'is_provider_of_record' => false,
            'active_from' => now(),
            'active_to' => null,
            'added_by_user_id' => (string) Str::uuid7(),
            'removed_by_user_id' => null,
            'end_reason' => null,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forEncounter(string $encounterId): static
    {
        return $this->state(['encounter_id' => $encounterId]);
    }

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function forStaff(string $staffUserId): static
    {
        return $this->state(['staff_user_id' => $staffUserId]);
    }

    public function addedBy(string $userId): static
    {
        return $this->state(['added_by_user_id' => $userId]);
    }

    public function withRole(string $teamRole): static
    {
        return $this->state(['team_role' => $teamRole]);
    }

    /** The encounter's provider-of-record (active). */
    public function providerOfRecord(): static
    {
        return $this->state(['is_provider_of_record' => true]);
    }

    /** An ENDED membership — `active_to` set. */
    public function ended(): static
    {
        return $this->state([
            'is_provider_of_record' => false,
            'active_to' => now(),
            'removed_by_user_id' => (string) Str::uuid7(),
        ]);
    }
}
