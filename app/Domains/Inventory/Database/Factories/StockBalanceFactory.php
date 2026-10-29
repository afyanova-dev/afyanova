<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Database\Factories;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
{
    protected $model = StockBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'item_id' => (string) Str::uuid7(),
            'location_id' => (string) Str::uuid7(),
            'batch_id' => null,
            'on_hand_qty' => '0',
            'reserved_qty' => '0',
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function onHand(string $qty): static
    {
        return $this->state(['on_hand_qty' => $qty]);
    }
}
