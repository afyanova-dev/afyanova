<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Database\Factories;

use App\Domains\BedWard\Domain\Model\Room;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'ward_id' => (string) Str::uuid7(),
            'name' => 'Room '.fake()->numberBetween(101, 199),
            'room_type' => 'STANDARD',
            'isolation_capable' => false,
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

    public function inWard(string $wardId): static
    {
        return $this->state(['ward_id' => $wardId]);
    }
}
