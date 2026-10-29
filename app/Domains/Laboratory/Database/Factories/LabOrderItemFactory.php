<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabOrderItem>
 */
class LabOrderItemFactory extends Factory
{
    protected $model = LabOrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'order_id' => (string) Str::uuid7(),
            'test_definition_id' => (string) Str::uuid7(),
            'test_code' => 'FBC',
            'test_name' => 'Full Blood Count',
            'test_definition_version' => 1,
            'status' => LabOrderItem::STATUS_ORDERED,
            'version' => 0,
        ];
    }

    public function forOrder(string $orderId): static
    {
        return $this->state(['order_id' => $orderId]);
    }

    public function forDefinition(string $definitionId): static
    {
        return $this->state(['test_definition_id' => $definitionId]);
    }
}
