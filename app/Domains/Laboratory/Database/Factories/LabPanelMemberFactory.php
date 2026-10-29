<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabPanelMember;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabPanelMember>
 */
class LabPanelMemberFactory extends Factory
{
    protected $model = LabPanelMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'panel_id' => (string) Str::uuid7(),
            'test_definition_id' => (string) Str::uuid7(),
            'sequence' => 1,
            'version' => 0,
        ];
    }

    public function forPanel(string $panelId): static
    {
        return $this->state(['panel_id' => $panelId]);
    }

    public function forDefinition(string $definitionId): static
    {
        return $this->state(['test_definition_id' => $definitionId]);
    }
}
