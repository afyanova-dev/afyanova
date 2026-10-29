<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\EvaluationSettings;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EvaluationSettings>
 */
class EvaluationSettingsFactory extends Factory
{
    protected $model = EvaluationSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'commercial_weight' => 40,
            'technical_weight' => 30,
            'quality_weight' => 15,
            'delivery_weight' => 15,
            'commercial_required' => true,
            'technical_required' => true,
            'quality_required' => true,
            'delivery_required' => true,
            'pass_threshold' => null,
            'evaluation_required_above' => null,
            'version' => 1,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
