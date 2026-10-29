<?php

declare(strict_types=1);

namespace App\Domains\Organization\Database\Factories;

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Organization::factory(),   // placeholder — override with forTenant()
            'organization_id' => Organization::factory(),
            'name' => fake()->city().' Health Centre',
            'code' => strtoupper(fake()->unique()->bothify('FAC-####')),
            'facility_type' => 'HEALTH_CENTRE',
            'care_level' => 'HEALTH_CENTRE',
            'ownership_type' => 'PUBLIC',
            'status' => 'ACTIVE',
        ];
    }

    public function forTenant(string $tenantId, ?string $organizationId = null): static
    {
        return $this->state(fn () => [
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId ?? Organization::factory()->forTenant($tenantId),
        ]);
    }

    public function ofType(string $type, string $level): static
    {
        return $this->state(['facility_type' => $type, 'care_level' => $level]);
    }
}
