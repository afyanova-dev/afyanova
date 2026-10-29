<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Database\Factories;

use App\Domains\BedWard\Domain\Model\Ward;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ward>
 */
class WardFactory extends Factory
{
    protected $model = Ward::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'code' => 'WARD-'.fake()->unique()->bothify('??-##'),
            'name' => implode(' ', (array) fake()->words(2)).' Ward',
            'ward_type' => 'MEDICAL',
            'capability_link' => 'INPATIENT_CARE',
            'capacity' => fake()->numberBetween(10, 50),
            'status' => Ward::STATUS_ACTIVE,
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

    public function withType(string $type): static
    {
        return $this->state(['ward_type' => $type]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
