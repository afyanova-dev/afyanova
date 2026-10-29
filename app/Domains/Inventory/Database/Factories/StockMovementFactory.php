<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Database\Factories;

use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'movement_number' => 'MOV-'.sprintf('%08d', $this->faker->unique()->numberBetween(1, 99999999)),
            'movement_type' => StockMovement::TYPE_RECEIPT,
            'direction' => StockMovement::DIRECTION_IN,
            'quantity' => '10',
            'uom' => 'TABLET',
            'entered_unit' => null,
            'entered_quantity' => null,
            'pack_size' => null,
            'item_id' => (string) Str::uuid7(),
            'location_id' => (string) Str::uuid7(),
            'batch_id' => null,
            'source_type' => 'PROCUREMENT',
            'source_ref' => null,
            'po_ref' => null,
            'reason_text' => null,
            'actor_user_id' => (string) Str::uuid7(),
            'occurred_at' => CarbonImmutable::now(),
            'recorded_at' => CarbonImmutable::now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
