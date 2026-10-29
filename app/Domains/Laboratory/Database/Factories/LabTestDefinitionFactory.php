<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabTestDefinition>
 */
class LabTestDefinitionFactory extends Factory
{
    protected $model = LabTestDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'LAB-TEST-'.Str::upper(Str::random(6)),
            'name' => 'Full Blood Count',
            'category' => 'HAEMATOLOGY',
            'description' => 'Automated full blood count with differential.',
            'specimen_type' => 'WHOLE_BLOOD',
            'container_type' => 'EDTA_TUBE',
            'parameters' => [
                ['code' => 'HB', 'name' => 'Haemoglobin', 'unit' => 'g/dL'],
                ['code' => 'WBC', 'name' => 'White cell count', 'unit' => '10^9/L'],
            ],
            'required_capability_code' => null,
            'availability_status' => LabTestDefinition::AVAILABILITY_AVAILABLE,
            'turnaround_target_minutes' => 60,
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

    public function withAvailability(string $status): static
    {
        return $this->state(['availability_status' => $status]);
    }

    public function disabledForOrdering(): static
    {
        return $this->state(['availability_status' => LabTestDefinition::AVAILABILITY_DISABLED]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
