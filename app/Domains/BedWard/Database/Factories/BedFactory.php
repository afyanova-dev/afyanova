<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Database\Factories;

use App\Domains\BedWard\Domain\Model\Bed;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Bed>
 */
class BedFactory extends Factory
{
    protected $model = Bed::class;

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
            'room_id' => null,
            'label' => 'Bed-'.fake()->unique()->numerify('###'),
            'bed_class' => 'GENERAL',
            'features' => [],
            'status' => Bed::STATUS_AVAILABLE,
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

    public function inRoom(?string $roomId): static
    {
        return $this->state(['room_id' => $roomId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function withClass(string $bedClass): static
    {
        return $this->state(['bed_class' => $bedClass]);
    }
}
