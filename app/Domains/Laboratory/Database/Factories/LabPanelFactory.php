<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabPanel;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabPanel>
 */
class LabPanelFactory extends Factory
{
    protected $model = LabPanel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'PANEL-'.Str::upper(Str::random(5)),
            'name' => 'Renal Function Panel',
            'category' => 'CLINICAL_CHEMISTRY',
            'description' => 'Urea, electrolytes and creatinine.',
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

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
