<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Database\Factories;

use App\Domains\Radiology\Domain\Model\ImagingModality;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImagingModality>
 */
class ImagingModalityFactory extends Factory
{
    protected $model = ImagingModality::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'MOD-'.Str::upper(Str::random(4)),
            'name' => 'Computed Tomography',
            'category' => 'CROSS_SECTIONAL',
            'description' => 'CT scanner suite.',
            'required_capability_code' => null,
            'operational_status' => ImagingModality::STATUS_ACTIVE,
            'active' => true,
            'version' => 1,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function withCode(string $code): static
    {
        return $this->state(['code' => Str::upper($code)]);
    }

    public function requiringCapability(string $capabilityCode): static
    {
        return $this->state(['required_capability_code' => $capabilityCode]);
    }

    public function suspended(): static
    {
        return $this->state(['operational_status' => ImagingModality::STATUS_SUSPENDED]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
