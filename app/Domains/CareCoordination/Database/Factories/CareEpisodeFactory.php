<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Database\Factories;

use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CareEpisode>
 */
class CareEpisodeFactory extends Factory
{
    protected $model = CareEpisode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'type' => 'PREGNANCY',
            'subtype' => null,
            'status' => CareEpisode::STATUS_OPEN,
            'override_group' => null,
            'opened_at' => now(),
            'closed_at' => null,
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

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function ofType(string $type, ?string $subtype = null): static
    {
        return $this->state(['type' => $type, 'subtype' => $subtype]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function asOverride(): static
    {
        return $this->state(['override_group' => (string) Str::uuid7()]);
    }
}
