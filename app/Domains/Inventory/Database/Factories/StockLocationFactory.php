<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Database\Factories;

use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockLocation>
 */
class StockLocationFactory extends Factory
{
    protected $model = StockLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'department_id' => null,
            'code' => 'LOC-'.Str::upper(Str::random(6)),
            'name' => 'Main Store',
            'location_type' => StockLocation::TYPE_STORE,
            'parent_location_id' => null,
            'active' => true,
            'version' => 1,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
