<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Database\Factories;

use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockAdjustment>
 */
class StockAdjustmentFactory extends Factory
{
    protected $model = StockAdjustment::class;

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
            'direction' => StockAdjustment::DIRECTION_DECREASE,
            'quantity' => '3',
            'reason_code' => 'DAMAGED',
            'reason_text' => null,
            'witness_name' => null,
            'status' => StockAdjustment::STATUS_PENDING_APPROVAL,
            'requested_by_user_id' => (string) Str::uuid7(),
            'requested_at' => CarbonImmutable::now(),
            'approved_by_user_id' => null,
            'approved_at' => null,
            'rejection_reason' => null,
            'resulting_movement_id' => null,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
