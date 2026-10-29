<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OperatingRoom>
 */
class OperatingRoomFactory extends Factory
{
    protected $model = OperatingRoom::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'org_room_id' => (string) Str::uuid7(),
            'label' => 'OR-'.fake()->unique()->numerify('##'),
            'status' => OperatingRoom::STATUS_AVAILABLE,
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

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
