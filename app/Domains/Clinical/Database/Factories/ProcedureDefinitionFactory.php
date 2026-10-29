<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProcedureDefinition>
 */
class ProcedureDefinitionFactory extends Factory
{
    protected $model = ProcedureDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'PROC-DEF-'.Str::upper(Str::random(6)),
            'name' => 'Wound Dressing',
            'category' => 'DRESSING_WOUND_CARE',
            'description' => 'Routine wound dressing change.',
            'required_capability_code' => null,
            'requires_site' => false,
            'requires_laterality' => false,
            'requires_readiness_check' => false,
            'material_requirements' => null,
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
        return $this->state(['code' => $code]);
    }

    public function requiringCapability(string $capabilityCode): static
    {
        return $this->state(['required_capability_code' => $capabilityCode]);
    }

    public function requiringSite(): static
    {
        return $this->state(['requires_site' => true]);
    }

    public function requiringLaterality(): static
    {
        return $this->state(['requires_laterality' => true]);
    }

    public function requiringReadinessCheck(): static
    {
        return $this->state(['requires_readiness_check' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
