<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Database\Factories;

use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CareEpisodeEncounter>
 */
class CareEpisodeEncounterFactory extends Factory
{
    protected $model = CareEpisodeEncounter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'care_episode_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'role' => null,
            'status' => CareEpisodeEncounter::STATUS_LINKED,
            'added_at' => now(),
            'added_by_user_id' => (string) Str::uuid7(),
            'added_reason' => null,
            'detached_at' => null,
            'detached_by_user_id' => null,
            'detach_reason' => null,
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

    public function forEpisode(string $careEpisodeId): static
    {
        return $this->state(['care_episode_id' => $careEpisodeId]);
    }

    public function forEncounter(string $encounterId): static
    {
        return $this->state(['encounter_id' => $encounterId]);
    }

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function detached(): static
    {
        return $this->state([
            'status' => CareEpisodeEncounter::STATUS_DETACHED,
            'detached_at' => now(),
            'detached_by_user_id' => (string) Str::uuid7(),
            'detach_reason' => 'Linked in error.',
        ]);
    }
}
